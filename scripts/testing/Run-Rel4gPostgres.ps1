[CmdletBinding()]
param(
    [string[]] $TestPath = @('tests/Feature/TechnicalServicePostgresIsolationTest.php'),

    [string] $Filter = '',

    [string[]] $ExcludeGroup = @(),

    [ValidateRange(30, 1800)]
    [int] $TestTimeoutSeconds = 600,

    [string] $EvidenceDirectory = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$requestedEvidenceDirectory = $EvidenceDirectory

$script:Stage = 'preflight'
$script:ContainerId = $null
$script:ContainerName = $null
$script:NetworkId = $null
$script:NetworkName = $null
$script:Nonce = $null
$script:TemporaryDirectory = $null
$script:WorkerRegistry = $null
$script:PhpBinary = $null
$script:DockerBinary = $null
$script:CleanupFailed = $false
$script:DatabaseName = $null
$script:RunStartedAtUtc = [DateTime]::UtcNow.ToString('o')
$script:TestExitCode = $null
$script:EvidenceDirectory = $null
$script:EvidenceStdOutFile = $null
$script:EvidenceStdErrFile = $null
$script:EvidenceJunitFile = $null
$script:EvidenceResultFile = $null
$script:EvidenceFailureSummaryFile = $null
$script:EvidencePostgreSqlStateFile = $null
$script:EvidencePostgreSqlInspectFile = $null
$script:EvidencePostgreSqlReadyFile = $null
$script:EvidencePostgreSqlLogFile = $null
$script:TestCommand = $null
$script:FailureMessage = $null
$script:SensitiveValues = @()
$script:Versions = [ordered]@{}
$script:PostgreSqlEvidence = [ordered]@{}

$projectRoot = [IO.Path]::GetFullPath([IO.Path]::Combine($PSScriptRoot, '..', '..'))
$removedDatabaseVariables = @(
    'DB_URL',
    'DATABASE_URL',
    'PGHOST',
    'PGPORT',
    'PGDATABASE',
    'PGSERVICE',
    'PGSERVICEFILE'
)

function Protect-EvidenceText {
    param([AllowEmptyString()][string] $Text)

    $safe = [string] $Text

    foreach ($value in $script:SensitiveValues) {
        if (-not [string]::IsNullOrEmpty($value)) {
            $safe = $safe.Replace([string] $value, '[REDACTED]')
        }
    }

    $safe = [regex]::Replace(
        $safe,
        '(?im)\b(DB_PASSWORD|PGPASSWORD|POSTGRES_PASSWORD|API_KEY|TOKEN|SECRET)\b(\s*[:=]\s*)[^\s\r\n]+',
        '$1$2[REDACTED]'
    )
    $safe = [regex]::Replace(
        $safe,
        '(?im)(Authorization\s*:\s*)(?:Bearer|Basic)\s+[^\s\r\n]+',
        '$1[REDACTED]'
    )
    $safe = [regex]::Replace(
        $safe,
        '(?i)(postgres(?:ql)?://)[^@\s/]+@',
        '$1[REDACTED]@'
    )

    return $safe
}

function Write-RedactedEvidenceText {
    param(
        [Parameter(Mandatory)][string] $Path,
        [AllowEmptyString()][string] $Text
    )

    if (-not $script:EvidenceDirectory) {
        throw 'evidence_directory_unavailable'
    }

    $fullPath = [IO.Path]::GetFullPath($Path)
    $separator = [IO.Path]::DirectorySeparatorChar
    $evidencePrefix = $script:EvidenceDirectory.TrimEnd('\', '/') + $separator
    $comparison = if ($IsWindows) { [StringComparison]::OrdinalIgnoreCase } else { [StringComparison]::Ordinal }

    if (-not $fullPath.StartsWith($evidencePrefix, $comparison)) {
        throw 'evidence_file_scope_invalid'
    }

    [IO.File]::WriteAllText($fullPath, (Protect-EvidenceText -Text $Text), [Text.UTF8Encoding]::new($false))
}

function Protect-EvidenceFile {
    param([Parameter(Mandatory)][string] $Path)

    if (Test-Path -LiteralPath $Path) {
        Write-RedactedEvidenceText -Path $Path -Text ([IO.File]::ReadAllText($Path))
    }
}

function New-EvidenceDirectory {
    param(
        [AllowEmptyString()][string] $RequestedPath,
        [Parameter(Mandatory)][string] $Nonce
    )

    $path = if ([string]::IsNullOrWhiteSpace($RequestedPath)) {
        Join-Path ([IO.Path]::GetTempPath()) ('emaks-pr92-rel4g-evidence-' + $Nonce)
    }
    else {
        $RequestedPath
    }
    $fullPath = [IO.Path]::GetFullPath($path)
    $separator = [IO.Path]::DirectorySeparatorChar
    $projectPrefix = $projectRoot.TrimEnd('\', '/') + $separator
    $comparison = if ($IsWindows) { [StringComparison]::OrdinalIgnoreCase } else { [StringComparison]::Ordinal }

    if ($fullPath -eq $projectRoot -or $fullPath.StartsWith($projectPrefix, $comparison)) {
        throw 'evidence_directory_inside_repository'
    }

    if (Test-Path -LiteralPath $fullPath) {
        if (-not (Get-Item -LiteralPath $fullPath).PSIsContainer -or
            @(Get-ChildItem -LiteralPath $fullPath -Force).Count -ne 0) {
            throw 'evidence_directory_not_empty'
        }
    }
    else {
        [void] (New-Item -ItemType Directory -Path $fullPath)
    }

    if ($IsWindows) {
        $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
        $security = [Security.AccessControl.DirectorySecurity]::new()
        $security.SetAccessRuleProtection($true, $false)
        $rule = [Security.AccessControl.FileSystemAccessRule]::new(
            $sid,
            [Security.AccessControl.FileSystemRights]::FullControl,
            [Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit',
            [Security.AccessControl.PropagationFlags]::None,
            [Security.AccessControl.AccessControlType]::Allow
        )
        $security.AddAccessRule($rule)
        Set-Acl -LiteralPath $fullPath -AclObject $security
    }
    else {
        $mode = [IO.UnixFileMode]::UserRead -bor [IO.UnixFileMode]::UserWrite -bor [IO.UnixFileMode]::UserExecute
        [IO.File]::SetUnixFileMode($fullPath, $mode)

        if ([IO.File]::GetUnixFileMode($fullPath) -ne $mode) {
            throw 'evidence_directory_permissions_invalid'
        }
    }

    return $fullPath
}

function Get-OptionalToolVersion {
    param(
        [Parameter(Mandatory)][string] $FilePath,
        [Parameter(Mandatory)][string[]] $ArgumentList
    )

    try {
        $result = Invoke-BoundedProcess -FilePath $FilePath -ArgumentList $ArgumentList -TimeoutSeconds 30

        if ($result.ExitCode -ne 0) {
            return 'unavailable'
        }

        return (@($result.StdOut -split '\r?\n' | Where-Object { $_ -ne '' })[0]).Trim()
    }
    catch {
        return 'unavailable'
    }
}

function Get-JunitFailureRecords {
    if (-not $script:EvidenceJunitFile -or -not (Test-Path -LiteralPath $script:EvidenceJunitFile)) {
        return @()
    }

    try {
        [xml] $junit = Get-Content -LiteralPath $script:EvidenceJunitFile -Raw
        $records = @()

        foreach ($testCase in @($junit.SelectNodes('//testcase[error or failure]'))) {
            $detail = $testCase.SelectSingleNode('./error | ./failure')
            $records += [ordered]@{
                class = $testCase.GetAttribute('class')
                method = $testCase.GetAttribute('name')
                type = $detail.GetAttribute('type')
                message = (Protect-EvidenceText -Text $detail.InnerText.Trim())
                file = $testCase.GetAttribute('file')
                line = $testCase.GetAttribute('line')
            }
        }

        return $records
    }
    catch {
        return @()
    }
}

function Get-FirstExceptionSummary {
    $records = @(Get-JunitFailureRecords)
    $firstError = @($records | Where-Object { $_.type -notlike '*ExpectationFailedException' } | Select-Object -First 1)
    $record = if ($firstError.Count -gt 0) { $firstError[0] } elseif ($records.Count -gt 0) { $records[0] } else { $null }

    if ($record) {
        return @(
            'TEST_CLASS: ' + $record.class
            'TEST_METHOD: ' + $record.method
            'EXCEPTION_CLASS: ' + $record.type
            'TEST_FILE: ' + $record.file
            'TEST_LINE: ' + $record.line
            'EXCEPTION_BLOCK:'
            $record.message
        ) -join "`n"
    }

    $combined = ''

    foreach ($path in @($script:EvidenceStdOutFile, $script:EvidenceStdErrFile)) {
        if ($path -and (Test-Path -LiteralPath $path)) {
            $combined += [IO.File]::ReadAllText($path) + "`n"
        }
    }

    $lines = @($combined -split '\r?\n')
    $start = -1

    for ($index = 0; $index -lt $lines.Count; $index++) {
        if ($lines[$index] -match 'Test Errored|PDOException|QueryException|ErrorException|RuntimeException|TypeError') {
            $start = $index
            break
        }
    }

    if ($start -lt 0) {
        return 'EXCEPTION_BLOCK_UNAVAILABLE'
    }

    $end = [Math]::Min($lines.Count - 1, $start + 120)
    return ($lines[$start..$end] -join "`n").Trim()
}

function Invoke-BoundedProcess {
    param(
        [Parameter(Mandatory)]
        [string] $FilePath,

        [Parameter(Mandatory)]
        [string[]] $ArgumentList,

        [Parameter(Mandatory)]
        [int] $TimeoutSeconds,

        [string] $WorkingDirectory = $projectRoot,

        [hashtable] $Environment = @{},

        [string[]] $RemoveEnvironment = @(),

        [string] $StdOutPath = '',

        [string] $StdErrPath = ''
    )

    $startInfo = [Diagnostics.ProcessStartInfo]::new()
    $startInfo.FileName = $FilePath
    $startInfo.WorkingDirectory = $WorkingDirectory
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true

    foreach ($argument in $ArgumentList) {
        [void] $startInfo.ArgumentList.Add($argument)
    }

    foreach ($name in $RemoveEnvironment) {
        [void] $startInfo.Environment.Remove($name)
    }

    foreach ($entry in $Environment.GetEnumerator()) {
        $startInfo.Environment[$entry.Key] = [string] $entry.Value
    }

    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $startInfo

    if (-not $process.Start()) {
        throw 'bounded_process_start_failed'
    }

    $streamToEvidence = $StdOutPath -ne '' -or $StdErrPath -ne ''

    if ($streamToEvidence) {
        $encoding = [Text.UTF8Encoding]::new($false)
        $stdoutBuilder = [Text.StringBuilder]::new()
        $stderrBuilder = [Text.StringBuilder]::new()
        $stdoutWriter = $null
        $stderrWriter = $null
        $timedOut = $false

        try {
            if ($StdOutPath -ne '') {
                Write-RedactedEvidenceText -Path $StdOutPath -Text ''
                $stdoutWriter = [IO.StreamWriter]::new($StdOutPath, $false, $encoding)
                $stdoutWriter.AutoFlush = $true
            }

            if ($StdErrPath -ne '') {
                Write-RedactedEvidenceText -Path $StdErrPath -Text ''
                $stderrWriter = [IO.StreamWriter]::new($StdErrPath, $false, $encoding)
                $stderrWriter.AutoFlush = $true
            }

            $stdoutComplete = $false
            $stderrComplete = $false
            $stdoutTask = $process.StandardOutput.ReadLineAsync()
            $stderrTask = $process.StandardError.ReadLineAsync()
            $deadline = [DateTime]::UtcNow.AddSeconds($TimeoutSeconds)
            $drainDeadline = $null

            while (-not ($process.HasExited -and $stdoutComplete -and $stderrComplete)) {
                $madeProgress = $false

                if (-not $stdoutComplete -and $stdoutTask.IsCompleted) {
                    $line = $stdoutTask.GetAwaiter().GetResult()

                    if ($null -eq $line) {
                        $stdoutComplete = $true
                    }
                    else {
                        [void] $stdoutBuilder.AppendLine($line)

                        if ($stdoutWriter) {
                            $stdoutWriter.WriteLine((Protect-EvidenceText -Text $line))
                        }

                        $stdoutTask = $process.StandardOutput.ReadLineAsync()
                    }

                    $madeProgress = $true
                }

                if (-not $stderrComplete -and $stderrTask.IsCompleted) {
                    $line = $stderrTask.GetAwaiter().GetResult()

                    if ($null -eq $line) {
                        $stderrComplete = $true
                    }
                    else {
                        [void] $stderrBuilder.AppendLine($line)

                        if ($stderrWriter) {
                            $stderrWriter.WriteLine((Protect-EvidenceText -Text $line))
                        }

                        $stderrTask = $process.StandardError.ReadLineAsync()
                    }

                    $madeProgress = $true
                }

                if (-not $process.HasExited -and [DateTime]::UtcNow -ge $deadline) {
                    $timedOut = $true

                    try {
                        $process.Kill()
                        $terminated = $process.WaitForExit(5000)

                        if (-not $terminated -and -not $process.HasExited) {
                            $script:CleanupFailed = $true
                        }
                    }
                    catch {
                        $script:CleanupFailed = $true
                    }

                    $drainDeadline = [DateTime]::UtcNow.AddSeconds(10)
                }

                if ($drainDeadline -and [DateTime]::UtcNow -ge $drainDeadline -and
                    -not ($stdoutComplete -and $stderrComplete)) {
                    $script:CleanupFailed = $true
                    break
                }

                if (-not $madeProgress) {
                    Start-Sleep -Milliseconds 10
                }
            }

            if ($process.HasExited) {
                $process.WaitForExit()
            }
        }
        catch {
            if (-not $process.HasExited) {
                try {
                    $process.Kill()
                    [void] $process.WaitForExit(5000)
                }
                catch {
                    $script:CleanupFailed = $true
                }
            }

            throw
        }
        finally {
            if ($stdoutWriter) {
                $stdoutWriter.Dispose()
            }

            if ($stderrWriter) {
                $stderrWriter.Dispose()
            }
        }

        $stdout = $stdoutBuilder.ToString()
        $stderr = $stderrBuilder.ToString()

        if ($timedOut) {
            throw 'bounded_process_timeout'
        }

        return [pscustomobject]@{
            ExitCode = $process.ExitCode
            StdOut = $stdout
            StdErr = $stderr
            Pid = $process.Id
        }
    }

    $stdoutTask = $process.StandardOutput.ReadToEndAsync()
    $stderrTask = $process.StandardError.ReadToEndAsync()
    $completed = $process.WaitForExit($TimeoutSeconds * 1000)

    if (-not $completed) {
        try {
            $process.Kill()
            $terminated = $process.WaitForExit(5000)

            if (-not $terminated -and -not $process.HasExited) {
                $script:CleanupFailed = $true
            }
        }
        catch {
            $script:CleanupFailed = $true
        }

        $stdout = $stdoutTask.GetAwaiter().GetResult()
        $stderr = $stderrTask.GetAwaiter().GetResult()

        if ($StdOutPath -ne '') {
            Write-RedactedEvidenceText -Path $StdOutPath -Text $stdout
        }

        if ($StdErrPath -ne '') {
            Write-RedactedEvidenceText -Path $StdErrPath -Text $stderr
        }

        throw 'bounded_process_timeout'
    }

    $process.WaitForExit()
    $stdout = $stdoutTask.GetAwaiter().GetResult()
    $stderr = $stderrTask.GetAwaiter().GetResult()

    if ($StdOutPath -ne '') {
        Write-RedactedEvidenceText -Path $StdOutPath -Text $stdout
    }

    if ($StdErrPath -ne '') {
        Write-RedactedEvidenceText -Path $StdErrPath -Text $stderr
    }

    return [pscustomobject]@{
        ExitCode = $process.ExitCode
        StdOut = $stdout
        StdErr = $stderr
        Pid = $process.Id
    }
}

function Invoke-RequiredProcess {
    param(
        [Parameter(Mandatory)]
        [string] $FilePath,

        [Parameter(Mandatory)]
        [string[]] $ArgumentList,

        [Parameter(Mandatory)]
        [int] $TimeoutSeconds,

        [string] $WorkingDirectory = $projectRoot,

        [hashtable] $Environment = @{},

        [string[]] $RemoveEnvironment = @()
    )

    $result = Invoke-BoundedProcess -FilePath $FilePath -ArgumentList $ArgumentList -TimeoutSeconds $TimeoutSeconds -WorkingDirectory $WorkingDirectory -Environment $Environment -RemoveEnvironment $RemoveEnvironment

    if ($result.ExitCode -ne 0) {
        throw 'required_process_failed'
    }

    return $result
}

function Get-CommandPath {
    param([Parameter(Mandatory)][string] $Name)

    $command = Get-Command $Name -ErrorAction Stop

    if (-not $command.Source) {
        throw 'command_path_unavailable'
    }

    $path = [IO.Path]::GetFullPath($command.Source)

    if (-not $IsWindows) {
        $target = [IO.File]::ResolveLinkTarget($path, $true)

        if ($target) {
            return $target.FullName
        }
    }

    return $path
}

function Resolve-TestSelectionArguments {
    if ($TestPath.Count -eq 0) {
        throw 'test_selection_empty'
    }

    $testsRoot = [IO.Path]::GetFullPath([IO.Path]::Combine($projectRoot, 'tests'))
    $testsPrefix = $testsRoot.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    $comparison = if ($IsWindows) { [StringComparison]::OrdinalIgnoreCase } else { [StringComparison]::Ordinal }
    $arguments = @()

    foreach ($path in $TestPath) {
        if ([string]::IsNullOrWhiteSpace($path)) {
            throw 'test_selection_path_empty'
        }

        $resolved = [IO.Path]::GetFullPath([IO.Path]::Combine($projectRoot, $path))

        if (($resolved -ne $testsRoot -and -not $resolved.StartsWith($testsPrefix, $comparison)) -or
            -not (Test-Path -LiteralPath $resolved)) {
            throw 'test_selection_outside_tests_or_missing'
        }

        $arguments += [IO.Path]::GetRelativePath($projectRoot, $resolved)
    }

    if ($Filter -ne '') {
        if ($Filter.Length -gt 2000 -or $Filter -match '[\x00-\x1F]') {
            throw 'test_filter_invalid'
        }

        $arguments = @('--filter', $Filter) + $arguments
    }

    foreach ($group in $ExcludeGroup) {
        if ($group -notmatch '^[A-Za-z0-9_.-]+$') {
            throw 'test_exclude_group_invalid'
        }

        $arguments = @('--exclude-group', $group) + $arguments
    }

    return $arguments
}

function New-ExactDisposableDatabase {
    param([Parameter(Mandatory)][string] $DatabaseName)

    $expected = 'emaks_pr92_rel4g_test_' + $script:Nonce

    if ($DatabaseName -ne $expected -or $DatabaseName -notmatch '^emaks_pr92_rel4g_test_[a-f0-9]{12}$') {
        throw 'disposable_database_identity_invalid'
    }

    $created = Invoke-BoundedProcess -FilePath $script:DockerBinary -ArgumentList @(
        'exec',
        $script:ContainerId,
        'sh',
        '-ec',
        'PGPASSWORD="$POSTGRES_PASSWORD" createdb --host=127.0.0.1 --username="$POSTGRES_USER" --owner="$POSTGRES_USER" "$1"',
        'guard-createdb',
        $DatabaseName
    ) -TimeoutSeconds 30

    if ($created.ExitCode -ne 0) {
        throw 'disposable_database_create_failed'
    }
}

function Assert-GitScope {
    $head = (Invoke-RequiredProcess -FilePath $script:GitBinary -ArgumentList @('-C', $projectRoot, 'rev-parse', 'HEAD') -TimeoutSeconds 10).StdOut.Trim()
    $tree = (Invoke-RequiredProcess -FilePath $script:GitBinary -ArgumentList @('-C', $projectRoot, 'rev-parse', 'HEAD^{tree}') -TimeoutSeconds 10).StdOut.Trim()

    if ($head -ne $script:InitialHead -or $tree -ne $script:InitialTree) {
        throw 'git_identity_changed'
    }

    $index = Invoke-BoundedProcess -FilePath $script:GitBinary -ArgumentList @('-C', $projectRoot, 'diff', '--cached', '--quiet') -TimeoutSeconds 10

    if ($index.ExitCode -ne 0) {
        throw 'git_index_changed'
    }

    $status = (Invoke-RequiredProcess -FilePath $script:GitBinary -ArgumentList @('-C', $projectRoot, 'status', '--porcelain=v1', '--untracked-files=all') -TimeoutSeconds 10).StdOut

    if ($status -cne $script:InitialStatus) {
        throw 'git_worktree_changed'
    }
}

function New-SecureTemporaryDirectory {
    param([Parameter(Mandatory)][string] $Nonce)

    $path = Join-Path ([IO.Path]::GetTempPath()) ('emaks-pr92-rel4g-wp0a-' + $Nonce)

    if (Test-Path -LiteralPath $path) {
        throw 'temporary_directory_collision'
    }

    [void] (New-Item -ItemType Directory -Path $path)
    $script:TemporaryDirectory = [IO.Path]::GetFullPath($path)

    if ($IsWindows) {
        $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
        $security = [Security.AccessControl.DirectorySecurity]::new()
        $security.SetAccessRuleProtection($true, $false)
        $rule = [Security.AccessControl.FileSystemAccessRule]::new(
            $sid,
            [Security.AccessControl.FileSystemRights]::FullControl,
            [Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit',
            [Security.AccessControl.PropagationFlags]::None,
            [Security.AccessControl.AccessControlType]::Allow
        )
        $security.AddAccessRule($rule)
        Set-Acl -LiteralPath $path -AclObject $security
    }
    else {
        $mode = [IO.UnixFileMode]::UserRead -bor [IO.UnixFileMode]::UserWrite -bor [IO.UnixFileMode]::UserExecute
        [IO.File]::SetUnixFileMode($path, $mode)

        if ([IO.File]::GetUnixFileMode($path) -ne $mode) {
            throw 'temporary_directory_permissions_invalid'
        }
    }

    return [IO.Path]::GetFullPath($path)
}

function Remove-ExactTemporaryDirectory {
    param([Parameter(Mandatory)][string] $Path)

    $fullPath = [IO.Path]::GetFullPath($Path)
    $separator = [IO.Path]::DirectorySeparatorChar
    $temporaryRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\', '/') + $separator
    $comparison = if ($IsWindows) { [StringComparison]::OrdinalIgnoreCase } else { [StringComparison]::Ordinal }

    if (-not $fullPath.StartsWith($temporaryRoot, $comparison) -or
        -not ([IO.Path]::GetFileName($fullPath)).StartsWith('emaks-pr92-rel4g-wp0a-', [StringComparison]::Ordinal)) {
        throw 'temporary_directory_scope_invalid'
    }

    if (Test-Path -LiteralPath $fullPath) {
        Remove-Item -LiteralPath $fullPath -Recurse -Force
    }

    if (Test-Path -LiteralPath $fullPath) {
        throw 'temporary_directory_cleanup_failed'
    }
}

function Get-CanonicalContainerSnapshot {
    $probe = Invoke-BoundedProcess -FilePath $script:DockerBinary -ArgumentList @('container', 'inspect', '--format', '{{.Id}}', 'emaks-pr92-uat-db') -TimeoutSeconds 10

    if ($probe.ExitCode -ne 0) {
        if (Test-DockerObjectNotFound -Result $probe -Kind 'container') {
            return [ordered]@{
                Present = $false
            }
        }

        throw 'canonical_container_lookup_failed'
    }

    $id = $probe.StdOut.Trim()

    return [ordered]@{
        Present = $true
        Id = $id
        Status = (Invoke-RequiredProcess -FilePath $script:DockerBinary -ArgumentList @('container', 'inspect', '--format', '{{.State.Status}}', $id) -TimeoutSeconds 10).StdOut.Trim()
        Health = (Invoke-RequiredProcess -FilePath $script:DockerBinary -ArgumentList @('container', 'inspect', '--format', '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}', $id) -TimeoutSeconds 10).StdOut.Trim()
        StartedAt = (Invoke-RequiredProcess -FilePath $script:DockerBinary -ArgumentList @('container', 'inspect', '--format', '{{.State.StartedAt}}', $id) -TimeoutSeconds 10).StdOut.Trim()
        RestartCount = (Invoke-RequiredProcess -FilePath $script:DockerBinary -ArgumentList @('container', 'inspect', '--format', '{{.RestartCount}}', $id) -TimeoutSeconds 10).StdOut.Trim()
    }
}

function Wait-EphemeralDatabaseHealthy {
    param([Parameter(Mandatory)][string] $ContainerId)

    $deadline = [DateTime]::UtcNow.AddSeconds(90)

    do {
        $state = (Invoke-RequiredProcess -FilePath $script:DockerBinary -ArgumentList @('container', 'inspect', '--format', '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}', $ContainerId) -TimeoutSeconds 10).StdOut.Trim()

        if ($state -eq 'running|healthy') {
            return
        }

        if ($state.StartsWith('exited|', [StringComparison]::Ordinal)) {
            $exitCode = (Invoke-RequiredProcess -FilePath $script:DockerBinary -ArgumentList @('container', 'inspect', '--format', '{{.State.ExitCode}}', $ContainerId) -TimeoutSeconds 10).StdOut.Trim()
            $logProbe = Invoke-BoundedProcess -FilePath $script:DockerBinary -ArgumentList @('container', 'logs', '--tail', '80', $ContainerId) -TimeoutSeconds 10
            $logText = $logProbe.StdOut + "`n" + $logProbe.StdErr
            $failureClass = 'unknown'

            if ($logText -match '(?i)permission denied|operation not permitted|wrong ownership|could not change permissions|\bchmod\b|\bchown\b') {
                $failureClass = 'tmpfs_permissions'
            }
            elseif ($logText -match '(?i)no space left on device|out of memory') {
                $failureClass = 'resource_limit'
            }
            elseif ($logText -match '(?i)invalid value|unrecognized option') {
                $failureClass = 'postgres_option'
            }
            elseif ($logText -match '(?i)initdb: error') {
                $failureClass = 'initdb'
            }
            elseif ($logText -match '(?i)superuser password is not specified|POSTGRES_PASSWORD|password file.+empty') {
                $failureClass = 'credential_environment'
            }
            elseif ($logText -match '(?i)database files are incompatible|database directory.+not empty|PGDATA') {
                $failureClass = 'data_layout'
            }
            elseif ($logText -match '(?i)exec.+not found|no such file or directory') {
                $failureClass = 'entrypoint_execution'
            }
            elseif ($logText -match '(?i)fatal:|error:') {
                $failureClass = 'postgres_reported_error'
            }
            elseif ([string]::IsNullOrWhiteSpace($logText)) {
                $failureClass = 'no_logs'
            }

            $script:Stage = 'container_health_exited_' + $failureClass + '_code_' + $exitCode
            throw 'ephemeral_database_exited'
        }

        Start-Sleep -Milliseconds 500
    } while ([DateTime]::UtcNow -lt $deadline)

    throw 'ephemeral_database_health_timeout'
}

function Assert-ContainerCredentialEnvironment {
    $script:Stage = 'container_credential_environment'
    $environmentJson = (Invoke-RequiredProcess -FilePath $script:DockerBinary -ArgumentList @('container', 'inspect', '--format', '{{json .Config.Env}}', $script:ContainerId) -TimeoutSeconds 10).StdOut.Trim()
    $entries = @($environmentJson | ConvertFrom-Json)

    foreach ($name in @('POSTGRES_DB', 'POSTGRES_USER', 'POSTGRES_PASSWORD')) {
        $matches = @($entries | Where-Object { $_ -is [string] -and $_.StartsWith($name + '=', [StringComparison]::Ordinal) })

        if ($matches.Count -ne 1 -or $matches[0].Length -le ($name.Length + 1)) {
            throw 'container_credential_environment_missing'
        }
    }
}

function Assert-InContainerPostgreSqlConnection {
    $script:Stage = 'container_postgres_authenticated_probe'
    $probe = Invoke-BoundedProcess -FilePath $script:DockerBinary -ArgumentList @(
        'exec',
        $script:ContainerId,
        'sh',
        '-ec',
        'PGPASSWORD="$POSTGRES_PASSWORD" psql --no-psqlrc --host=127.0.0.1 --username="$POSTGRES_USER" --dbname="$POSTGRES_DB" --tuples-only --no-align --command="select 1"'
    ) -TimeoutSeconds 15

    if ($probe.ExitCode -ne 0 -or $probe.StdOut.Trim() -ne '1') {
        throw 'container_postgres_authenticated_probe_failed'
    }
}

function Wait-HostPostgreSqlReady {
    param(
        [Parameter(Mandatory)]
        [hashtable] $Environment,

        [Parameter(Mandatory)]
        [string[]] $RemoveEnvironment,

        [Parameter(Mandatory)]
        [int] $Port
    )

    $worker = [IO.Path]::Combine($projectRoot, 'tests', 'Support', 'TechnicalServiceDecisionRaceWorker.php')
    $deadline = [DateTime]::UtcNow.AddSeconds(45)
    $lastClass = 'none'
    $lastCode = 'none'
    $lastDetail = 'none'
    $lastWorkerStage = 'none'
    $tcpReady = $false
    $protocolReady = $false

    do {
        $client = [Net.Sockets.TcpClient]::new()

        try {
            $connect = $client.BeginConnect('127.0.0.1', $Port, $null, $null)
            $tcpReady = $connect.AsyncWaitHandle.WaitOne(2000)

            if ($tcpReady) {
                $client.EndConnect($connect)
            }
        }
        catch {
            $tcpReady = $false
        }
        finally {
            $client.Dispose()
        }

        if (-not $tcpReady) {
            Start-Sleep -Milliseconds 500
            continue
        }

        $protocolClient = [Net.Sockets.TcpClient]::new()

        try {
            $protocolClient.SendTimeout = 2000
            $protocolClient.ReceiveTimeout = 2000
            $protocolClient.Connect('127.0.0.1', $Port)
            $stream = $protocolClient.GetStream()
            $sslRequest = [byte[]](0, 0, 0, 8, 4, 210, 22, 47)
            $stream.Write($sslRequest, 0, $sslRequest.Length)
            $response = $stream.ReadByte()
            $protocolReady = $response -eq 78 -or $response -eq 83
        }
        catch {
            $protocolReady = $false
        }
        finally {
            $protocolClient.Dispose()
        }

        if (-not $protocolReady) {
            Start-Sleep -Milliseconds 500
            continue
        }

        $probe = Invoke-BoundedProcess -FilePath $script:PhpBinary -ArgumentList @(
            $worker,
            'connectivity',
            $script:Nonce
        ) -TimeoutSeconds 12 -Environment $Environment -RemoveEnvironment $RemoveEnvironment

        try {
            $payload = $probe.StdOut.Trim() | ConvertFrom-Json

            if ($probe.ExitCode -eq 0 -and $payload.ok -and $payload.mode -eq 'connectivity' -and $payload.outbound_guarded) {
                return
            }

            $lastClass = [string] $payload.class
            $lastCode = [string] $payload.code
            $lastDetail = [string] $payload.detail
            $lastWorkerStage = [string] $payload.stage
        }
        catch {
            # A malformed probe is treated as not ready and remains bounded.
        }

        Start-Sleep -Milliseconds 500
    } while ([DateTime]::UtcNow -lt $deadline)

    $tcpClass = if ($tcpReady) { 'tcp_ready' } else { 'tcp_unavailable' }
    $protocolClass = if ($protocolReady) { 'protocol_ready' } else { 'protocol_unavailable' }
    $serverClass = 'none'

    if ($lastCode -eq '08006') {
        $logProbe = Invoke-BoundedProcess -FilePath $script:DockerBinary -ArgumentList @('container', 'logs', '--tail', '120', $script:ContainerId) -TimeoutSeconds 10
        $logText = $logProbe.StdOut + "`n" + $logProbe.StdErr

        if ($logText -match '(?i)password authentication failed') {
            $serverClass = 'authentication'
        }
        elseif ($logText -match '(?i)no pg_hba\.conf entry') {
            $serverClass = 'hba'
        }
        elseif ($logText -match '(?i)could not accept SSL connection|SSL error|no encryption') {
            $serverClass = 'ssl'
        }
        elseif ($logText -match '(?i)database .+ does not exist') {
            $serverClass = 'database_missing'
        }
        elseif ($logText -match '(?i)role .+ does not exist') {
            $serverClass = 'role_missing'
        }
        elseif ($logText -match '(?i)unsupported frontend protocol|invalid length of startup packet') {
            $serverClass = 'protocol'
        }
    }

    $script:Stage = 'host_postgres_readiness_' + $tcpClass + '_' + $protocolClass + '_' + $lastWorkerStage + '_' + $lastClass + '_' + $lastCode + '_' + $lastDetail + '_' + $serverClass
    throw 'host_postgres_readiness_timeout'
}

function Assert-EphemeralDockerIsolation {
    $script:Stage = 'container_identity'
    $containerIdentity = [ordered]@{
        Id = (Invoke-RequiredProcess -FilePath $script:DockerBinary -ArgumentList @('container', 'inspect', '--format', '{{.Id}}', $script:ContainerId) -TimeoutSeconds 10).StdOut.Trim()
        Name = (Invoke-RequiredProcess -FilePath $script:DockerBinary -ArgumentList @('container', 'inspect', '--format', '{{.Name}}', $script:ContainerId) -TimeoutSeconds 10).StdOut.Trim().TrimStart('/')
        Scope = (Invoke-RequiredProcess -FilePath $script:DockerBinary -ArgumentList @('container', 'inspect', '--format', '{{index .Config.Labels "emaks.rel4g.scope"}}', $script:ContainerId) -TimeoutSeconds 10).StdOut.Trim()
        Nonce = (Invoke-RequiredProcess -FilePath $script:DockerBinary -ArgumentList @('container', 'inspect', '--format', '{{index .Config.Labels "emaks.rel4g.nonce"}}', $script:ContainerId) -TimeoutSeconds 10).StdOut.Trim()
        NetworkMode = (Invoke-RequiredProcess -FilePath $script:DockerBinary -ArgumentList @('container', 'inspect', '--format', '{{.HostConfig.NetworkMode}}', $script:ContainerId) -TimeoutSeconds 10).StdOut.Trim()
    }

    if ($containerIdentity.Id -ne $script:ContainerId -or
        $containerIdentity.Name -ne $script:ContainerName -or
        $containerIdentity.Scope -ne 'wp0a' -or
        $containerIdentity.Nonce -ne $script:Nonce -or
        $containerIdentity.NetworkMode -ne $script:NetworkName) {
        throw 'container_identity_or_network_mismatch'
    }

    $script:Stage = 'container_tmpfs_mount'
    $mountJson = (Invoke-RequiredProcess -FilePath $script:DockerBinary -ArgumentList @('container', 'inspect', '--format', '{{json .Mounts}}', $script:ContainerId) -TimeoutSeconds 10).StdOut.Trim()
    $mounts = @($mountJson | ConvertFrom-Json)

    if ($mounts.Count -ne 1 -or
        $mounts[0].Type -ne 'tmpfs' -or
        $mounts[0].Destination -ne '/var/lib/postgresql/data') {
        throw 'postgres_data_mount_not_exact_tmpfs'
    }

    $script:Stage = 'container_loopback_port'
    $requestedPortsJson = (Invoke-RequiredProcess -FilePath $script:DockerBinary -ArgumentList @('container', 'inspect', '--format', '{{json .HostConfig.PortBindings}}', $script:ContainerId) -TimeoutSeconds 10).StdOut.Trim()
    $requestedPorts = $requestedPortsJson | ConvertFrom-Json
    $requestedBindingProperty = $requestedPorts.PSObject.Properties['5432/tcp']
    $requestedBindings = if ($requestedBindingProperty) { @($requestedBindingProperty.Value) } else { @() }
    $loopbackPublishRequested = $requestedBindings.Count -eq 1 -and $requestedBindings[0].HostIp -eq '127.0.0.1'
    $portProbe = Invoke-BoundedProcess -FilePath $script:DockerBinary -ArgumentList @('port', $script:ContainerId, '5432/tcp') -TimeoutSeconds 10

    if ($portProbe.ExitCode -ne 0) {
        $networkInternal = (Invoke-RequiredProcess -FilePath $script:DockerBinary -ArgumentList @('network', 'inspect', '--format', '{{.Internal}}', $script:NetworkId) -TimeoutSeconds 10).StdOut.Trim()
        $failureClass = if ($loopbackPublishRequested -and $networkInternal -eq 'true') { 'internal_network_publish_unavailable' } else { 'publish_contract_missing' }
        $script:Stage = 'container_loopback_port_' + $failureClass
        throw 'postgres_port_command_failed'
    }

    $portOutput = $portProbe.StdOut.Trim()
    $bindings = @($portOutput -split "`r?`n" | Where-Object { $_ -ne '' })

    if ($bindings.Count -ne 1) {
        $script:Stage = 'container_loopback_port_binding_count'
        throw 'postgres_port_binding_count_invalid'
    }

    if ($bindings[0] -notmatch '^127\.0\.0\.1:(\d+)$') {
        $script:Stage = 'container_loopback_port_bind_address'
        throw 'postgres_port_binding_not_loopback'
    }

    $dynamicPort = [int] $Matches[1]

    if ($dynamicPort -lt 1024 -or $dynamicPort -gt 65535 -or $dynamicPort -eq 15433) {
        $script:Stage = 'container_loopback_port_dynamic_value'
        throw 'postgres_dynamic_port_invalid'
    }

    $script:Stage = 'network_identity'
    $networkIdentity = [ordered]@{
        Id = (Invoke-RequiredProcess -FilePath $script:DockerBinary -ArgumentList @('network', 'inspect', '--format', '{{.Id}}', $script:NetworkId) -TimeoutSeconds 10).StdOut.Trim()
        Name = (Invoke-RequiredProcess -FilePath $script:DockerBinary -ArgumentList @('network', 'inspect', '--format', '{{.Name}}', $script:NetworkId) -TimeoutSeconds 10).StdOut.Trim()
        Scope = (Invoke-RequiredProcess -FilePath $script:DockerBinary -ArgumentList @('network', 'inspect', '--format', '{{index .Labels "emaks.rel4g.scope"}}', $script:NetworkId) -TimeoutSeconds 10).StdOut.Trim()
        Nonce = (Invoke-RequiredProcess -FilePath $script:DockerBinary -ArgumentList @('network', 'inspect', '--format', '{{index .Labels "emaks.rel4g.nonce"}}', $script:NetworkId) -TimeoutSeconds 10).StdOut.Trim()
    }

    if ($networkIdentity.Id -ne $script:NetworkId -or
        $networkIdentity.Name -ne $script:NetworkName -or
        $networkIdentity.Scope -ne 'wp0a' -or
        $networkIdentity.Nonce -ne $script:Nonce) {
        throw 'network_identity_mismatch'
    }

    $script:Stage = 'network_membership'
    $containersJson = (Invoke-RequiredProcess -FilePath $script:DockerBinary -ArgumentList @('network', 'inspect', '--format', '{{json .Containers}}', $script:NetworkId) -TimeoutSeconds 10).StdOut.Trim()
    $containers = $containersJson | ConvertFrom-Json
    $members = @($containers.PSObject.Properties)

    if ($members.Count -ne 1 -or $members[0].Name -ne $script:ContainerId) {
        throw 'disposable_network_membership_invalid'
    }

    return $dynamicPort
}

function Test-ExactContainerIdentity {
    if (-not $script:ContainerId) {
        return $false
    }

    $probe = Invoke-BoundedProcess -FilePath $script:DockerBinary -ArgumentList @('container', 'inspect', '--format', '{{.Id}}|{{.Name}}|{{index .Config.Labels "emaks.rel4g.scope"}}|{{index .Config.Labels "emaks.rel4g.nonce"}}', $script:ContainerId) -TimeoutSeconds 10

    if ($probe.ExitCode -ne 0) {
        return $false
    }

    $parts = $probe.StdOut.Trim().Split('|')

    return $parts.Count -eq 4 -and
        $parts[0] -eq $script:ContainerId -and
        $parts[1].TrimStart('/') -eq $script:ContainerName -and
        $parts[2] -eq 'wp0a' -and
        $parts[3] -eq $script:Nonce
}

function Test-ExactNetworkIdentity {
    if (-not $script:NetworkId) {
        return $false
    }

    $probe = Invoke-BoundedProcess -FilePath $script:DockerBinary -ArgumentList @('network', 'inspect', '--format', '{{.Id}}|{{.Name}}|{{index .Labels "emaks.rel4g.scope"}}|{{index .Labels "emaks.rel4g.nonce"}}', $script:NetworkId) -TimeoutSeconds 10

    if ($probe.ExitCode -ne 0) {
        return $false
    }

    $parts = $probe.StdOut.Trim().Split('|')

    return $parts.Count -eq 4 -and
        $parts[0] -eq $script:NetworkId -and
        $parts[1] -eq $script:NetworkName -and
        $parts[2] -eq 'wp0a' -and
        $parts[3] -eq $script:Nonce
}

function Test-DockerObjectNotFound {
    param(
        [Parameter(Mandatory)]
        [pscustomobject] $Result,

        [Parameter(Mandatory)]
        [ValidateSet('container', 'network')]
        [string] $Kind
    )

    if ($Result.ExitCode -eq 0) {
        return $false
    }

    $message = $Result.StdOut + "`n" + $Result.StdErr

    if ($Kind -eq 'container') {
        return $message -match '(?i)no such (?:object|container)'
    }

    return $message -match '(?i)(?:no such network|network .+ not found)'
}

function Resolve-ExactContainerIdForCleanup {
    if (-not $script:ContainerName -or -not $script:Nonce) {
        return $null
    }

    $probe = Invoke-BoundedProcess -FilePath $script:DockerBinary -ArgumentList @(
        'container', 'inspect', '--format',
        '{{.Id}}|{{.Name}}|{{index .Config.Labels "emaks.rel4g.scope"}}|{{index .Config.Labels "emaks.rel4g.nonce"}}',
        $script:ContainerName
    ) -TimeoutSeconds 10

    if ($probe.ExitCode -ne 0) {
        if (Test-DockerObjectNotFound -Result $probe -Kind 'container') {
            return $null
        }

        throw 'container_cleanup_lookup_failed'
    }

    $parts = $probe.StdOut.Trim().Split('|')

    if ($parts.Count -ne 4 -or
        $parts[0] -notmatch '^[a-f0-9]{64}$' -or
        $parts[1].TrimStart('/') -ne $script:ContainerName -or
        $parts[2] -ne 'wp0a' -or
        $parts[3] -ne $script:Nonce -or
        ($script:ContainerId -and $script:ContainerId -ne $parts[0])) {
        throw 'container_cleanup_identity_mismatch'
    }

    $script:ContainerId = $parts[0]

    return $script:ContainerId
}

function Resolve-ExactNetworkIdForCleanup {
    if (-not $script:NetworkName -or -not $script:Nonce) {
        return $null
    }

    $probe = Invoke-BoundedProcess -FilePath $script:DockerBinary -ArgumentList @(
        'network', 'inspect', '--format',
        '{{.Id}}|{{.Name}}|{{index .Labels "emaks.rel4g.scope"}}|{{index .Labels "emaks.rel4g.nonce"}}',
        $script:NetworkName
    ) -TimeoutSeconds 10

    if ($probe.ExitCode -ne 0) {
        if (Test-DockerObjectNotFound -Result $probe -Kind 'network') {
            return $null
        }

        throw 'network_cleanup_lookup_failed'
    }

    $parts = $probe.StdOut.Trim().Split('|')

    if ($parts.Count -ne 4 -or
        $parts[0] -notmatch '^[a-f0-9]{64}$' -or
        $parts[1] -ne $script:NetworkName -or
        $parts[2] -ne 'wp0a' -or
        $parts[3] -ne $script:Nonce -or
        ($script:NetworkId -and $script:NetworkId -ne $parts[0])) {
        throw 'network_cleanup_identity_mismatch'
    }

    $script:NetworkId = $parts[0]

    return $script:NetworkId
}

function Assert-DockerObjectAbsent {
    param(
        [Parameter(Mandatory)]
        [ValidateSet('container', 'network')]
        [string] $Kind,

        [string] $Id,

        [Parameter(Mandatory)]
        [string] $Name
    )

    $noun = if ($Kind -eq 'container') { 'container' } else { 'network' }
    $references = @($Id, $Name | Where-Object { $_ } | Select-Object -Unique)

    foreach ($reference in $references) {
        $probe = Invoke-BoundedProcess -FilePath $script:DockerBinary -ArgumentList @($noun, 'inspect', '--format', '{{.Id}}', $reference) -TimeoutSeconds 10

        if ($probe.ExitCode -eq 0 -or -not (Test-DockerObjectNotFound -Result $probe -Kind $Kind)) {
            throw ($Kind + '_cleanup_verification_failed')
        }
    }
}

function Get-ExactProcessSnapshot {
    param([Parameter(Mandatory)][int] $Id)

    if ($IsWindows) {
        $lookupErrors = @()
        $process = Get-CimInstance Win32_Process -Filter "ProcessId = $Id" -ErrorAction SilentlyContinue -ErrorVariable lookupErrors

        if ($lookupErrors.Count -ne 0) {
            throw 'worker_process_lookup_failed'
        }

        if (-not $process) {
            return $null
        }

        return [pscustomobject]@{
            ExecutablePath = if ($process.ExecutablePath) { [IO.Path]::GetFullPath($process.ExecutablePath) } else { '' }
            CommandLine = [string] $process.CommandLine
        }
    }

    $processRoot = Join-Path '/proc' ([string] $Id)
    $commandLinePath = Join-Path $processRoot 'cmdline'
    $executableLink = Join-Path $processRoot 'exe'

    if (-not (Test-Path -LiteralPath $commandLinePath) -or -not (Test-Path -LiteralPath $executableLink)) {
        return $null
    }

    try {
        $target = [IO.File]::ResolveLinkTarget($executableLink, $true)
        $commandLine = [Text.Encoding]::UTF8.GetString([IO.File]::ReadAllBytes($commandLinePath)).Replace([char] 0, [char] ' ')

        return [pscustomobject]@{
            ExecutablePath = if ($target) { $target.FullName } else { '' }
            CommandLine = $commandLine
        }
    }
    catch {
        if (-not (Test-Path -LiteralPath $processRoot)) {
            return $null
        }

        throw
    }
}

function Stop-RecordedWorkers {
    if (-not $script:WorkerRegistry -or -not (Test-Path -LiteralPath $script:WorkerRegistry)) {
        return
    }

    $workerPath = [IO.Path]::GetFullPath([IO.Path]::Combine($projectRoot, 'tests', 'Support', 'TechnicalServiceDecisionRaceWorker.php'))
    $entries = @(Get-Content -LiteralPath $script:WorkerRegistry | Where-Object { $_ -ne '' } | Sort-Object -Unique)

    foreach ($entry in $entries) {
        $parts = $entry.Split('|')

        if ($parts.Count -ne 3 -or $parts[0] -notmatch '^\d+$' -or $parts[1] -ne $script:Nonce -or $parts[2] -ne 'TechnicalServiceDecisionRaceWorker.php') {
            $script:CleanupFailed = $true
            continue
        }

        $pidValue = [int] $parts[0]
        $process = Get-ExactProcessSnapshot -Id $pidValue

        if (-not $process) {
            continue
        }

        $executable = [string] $process.ExecutablePath
        $commandLine = [string] $process.CommandLine

        if ($executable -ne $script:PhpBinary -or
            -not $commandLine.Contains($workerPath, [StringComparison]::OrdinalIgnoreCase) -or
            -not $commandLine.Contains($script:Nonce, [StringComparison]::Ordinal)) {
            $script:CleanupFailed = $true
            continue
        }

        Stop-Process -Id $pidValue -Force

        $deadline = [DateTime]::UtcNow.AddSeconds(5)
        do {
            Start-Sleep -Milliseconds 100
            $stillRunning = Get-ExactProcessSnapshot -Id $pidValue
        } while ($stillRunning -and [DateTime]::UtcNow -lt $deadline)

        if ($stillRunning) {
            $script:CleanupFailed = $true
        }
    }
}

function Invoke-ExactCleanup {
    try {
        Stop-RecordedWorkers
    }
    catch {
        $script:CleanupFailed = $true
    }

    if ($script:ContainerName) {
        try {
            $containerId = Resolve-ExactContainerIdForCleanup

            if ($containerId) {
                if (-not (Test-ExactContainerIdentity)) {
                    throw 'container_cleanup_identity_mismatch'
                }

                $remove = Invoke-BoundedProcess -FilePath $script:DockerBinary -ArgumentList @('container', 'rm', '--force', $containerId) -TimeoutSeconds 30

                if ($remove.ExitCode -ne 0) {
                    throw 'container_cleanup_failed'
                }
            }

            Assert-DockerObjectAbsent -Kind 'container' -Id $script:ContainerId -Name $script:ContainerName
        }
        catch {
            $script:CleanupFailed = $true
        }
    }

    if ($script:NetworkName) {
        try {
            $networkId = Resolve-ExactNetworkIdForCleanup

            if ($networkId) {
                if (-not (Test-ExactNetworkIdentity)) {
                    throw 'network_cleanup_identity_mismatch'
                }

                $remove = Invoke-BoundedProcess -FilePath $script:DockerBinary -ArgumentList @('network', 'rm', $networkId) -TimeoutSeconds 30

                if ($remove.ExitCode -ne 0) {
                    throw 'network_cleanup_failed'
                }
            }

            Assert-DockerObjectAbsent -Kind 'network' -Id $script:NetworkId -Name $script:NetworkName
        }
        catch {
            $script:CleanupFailed = $true
        }
    }

    if ($script:TemporaryDirectory) {
        try {
            Remove-ExactTemporaryDirectory -Path $script:TemporaryDirectory
        }
        catch {
            $script:CleanupFailed = $true
        }
    }
}

function Write-PostgreSqlContainerEvidence {
    param([Parameter(Mandatory)][string] $Phase)

    if (-not $script:EvidenceDirectory) {
        return
    }

    $capture = [ordered]@{
        captured_at_utc = [DateTime]::UtcNow.ToString('o')
        phase = $Phase
        container_id = $script:ContainerId
        container_name = $script:ContainerName
        present = $false
        state = 'unavailable'
        health = 'unavailable'
        restart_count = $null
        exit_code = $null
        oom_killed = $null
        inspect_exit_code = $null
        pg_isready_exit_code = $null
        pg_isready_result = 'unavailable'
        logs_exit_code = $null
        capture_error = $null
    }
    $inspectText = '{}'
    $readyText = 'PG_ISREADY_UNAVAILABLE'
    $logText = 'POSTGRESQL_LOGS_UNAVAILABLE'

    try {
        if (-not $script:DockerBinary -or -not $script:ContainerId) {
            throw 'postgresql_container_identity_unavailable'
        }

        $inspect = Invoke-BoundedProcess -FilePath $script:DockerBinary -ArgumentList @(
            'container', 'inspect', '--format', '{{json .State}}', $script:ContainerId
        ) -TimeoutSeconds 15
        $capture.inspect_exit_code = $inspect.ExitCode

        if ($inspect.ExitCode -eq 0 -and -not [string]::IsNullOrWhiteSpace($inspect.StdOut)) {
            $capture.present = $true
            $inspectText = $inspect.StdOut.Trim()
            $state = $inspectText | ConvertFrom-Json
            $capture.state = [string] $state.Status
            $capture.health = if ($state.Health) { [string] $state.Health.Status } else { 'none' }
            $capture.exit_code = [int] $state.ExitCode
            $capture.oom_killed = [bool] $state.OOMKilled

            $restart = Invoke-BoundedProcess -FilePath $script:DockerBinary -ArgumentList @(
                'container', 'inspect', '--format', '{{.RestartCount}}', $script:ContainerId
            ) -TimeoutSeconds 15

            if ($restart.ExitCode -eq 0 -and $restart.StdOut.Trim() -match '^\d+$') {
                $capture.restart_count = [int] $restart.StdOut.Trim()
            }

            $ready = Invoke-BoundedProcess -FilePath $script:DockerBinary -ArgumentList @(
                'exec', $script:ContainerId, 'sh', '-ec',
                'pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
            ) -TimeoutSeconds 15
            $capture.pg_isready_exit_code = $ready.ExitCode
            $readyText = ($ready.StdOut + $ready.StdErr).Trim()
            $capture.pg_isready_result = if ([string]::IsNullOrWhiteSpace($readyText)) {
                'no_output'
            }
            else {
                $readyText
            }

            $logs = Invoke-BoundedProcess -FilePath $script:DockerBinary -ArgumentList @(
                'container', 'logs', '--tail', '300', '--timestamps', $script:ContainerId
            ) -TimeoutSeconds 30
            $capture.logs_exit_code = $logs.ExitCode
            $logText = ($logs.StdOut + $logs.StdErr).Trim()
        }
        else {
            $inspectText = ($inspect.StdOut + $inspect.StdErr).Trim()
            $capture.capture_error = 'postgresql_container_inspect_failed'
        }
    }
    catch {
        $capture.capture_error = Protect-EvidenceText -Text $_.Exception.Message
    }

    Write-RedactedEvidenceText -Path $script:EvidencePostgreSqlInspectFile -Text $inspectText
    Write-RedactedEvidenceText -Path $script:EvidencePostgreSqlReadyFile -Text $readyText
    Write-RedactedEvidenceText -Path $script:EvidencePostgreSqlLogFile -Text $logText
    Write-RedactedEvidenceText -Path $script:EvidencePostgreSqlStateFile -Text ($capture | ConvertTo-Json -Depth 8)
    $script:PostgreSqlEvidence = $capture
}

$runFailed = $false
$testCount = 0
$assertionCount = 0

try {
    $script:GitBinary = Get-CommandPath -Name 'git'
    $script:DockerBinary = Get-CommandPath -Name 'docker'
    $script:PhpBinary = Get-CommandPath -Name 'php'
    $script:InitialHead = (Invoke-RequiredProcess -FilePath $script:GitBinary -ArgumentList @('-C', $projectRoot, 'rev-parse', 'HEAD') -TimeoutSeconds 10).StdOut.Trim()
    $script:InitialTree = (Invoke-RequiredProcess -FilePath $script:GitBinary -ArgumentList @('-C', $projectRoot, 'rev-parse', 'HEAD^{tree}') -TimeoutSeconds 10).StdOut.Trim()
    $script:InitialStatus = (Invoke-RequiredProcess -FilePath $script:GitBinary -ArgumentList @('-C', $projectRoot, 'status', '--porcelain=v1', '--untracked-files=all') -TimeoutSeconds 10).StdOut

    Assert-GitScope

    if (Test-Path -LiteralPath ([IO.Path]::Combine($projectRoot, 'bootstrap', 'cache', 'config.php'))) {
        Write-Output 'DECISION: RED_BLOCKED_REL4G_WP0A_CONFIG_CACHE_PRESENT'
        exit 2
    }

    $script:Stage = 'local_image'
    $image = Invoke-BoundedProcess -FilePath $script:DockerBinary -ArgumentList @('image', 'inspect', '--format', '{{json .Config.Volumes}}', 'postgres:16-alpine') -TimeoutSeconds 15

    if ($image.ExitCode -ne 0) {
        Write-Output 'DECISION: RED_BLOCKED_REL4G_WP0A_IMAGE_MISSING'
        exit 3
    }

    $declaredVolumes = $image.StdOut.Trim() | ConvertFrom-Json

    if (-not $declaredVolumes.PSObject.Properties['/var/lib/postgresql/data']) {
        throw 'image_data_volume_contract_changed'
    }

    $canonicalBefore = Get-CanonicalContainerSnapshot
    $script:Nonce = ([Guid]::NewGuid().ToString('N')).Substring(0, 12)
    $script:ContainerName = 'emaks-pr92-rel4g-wp0a-db-' + $script:Nonce
    $script:NetworkName = 'emaks-pr92-rel4g-wp0a-net-' + $script:Nonce
    $databaseName = 'emaks_pr92_rel4g_test_' + $script:Nonce
    $script:DatabaseName = $databaseName
    $databaseUser = 'rel4g_' + $script:Nonce
    $script:EvidenceDirectory = New-EvidenceDirectory -RequestedPath $requestedEvidenceDirectory -Nonce $script:Nonce
    $script:EvidenceStdOutFile = Join-Path $script:EvidenceDirectory 'stdout.log'
    $script:EvidenceStdErrFile = Join-Path $script:EvidenceDirectory 'stderr.log'
    $script:EvidenceJunitFile = Join-Path $script:EvidenceDirectory 'junit.xml'
    $script:EvidenceResultFile = Join-Path $script:EvidenceDirectory 'result.json'
    $script:EvidenceFailureSummaryFile = Join-Path $script:EvidenceDirectory 'failure-summary.txt'
    $script:EvidencePostgreSqlStateFile = Join-Path $script:EvidenceDirectory 'postgresql-state.json'
    $script:EvidencePostgreSqlInspectFile = Join-Path $script:EvidenceDirectory 'postgresql-inspect-state.json'
    $script:EvidencePostgreSqlReadyFile = Join-Path $script:EvidenceDirectory 'postgresql-pg-isready.txt'
    $script:EvidencePostgreSqlLogFile = Join-Path $script:EvidenceDirectory 'postgresql-container.log'

    $composerCommand = Get-Command 'composer' -ErrorAction SilentlyContinue
    $script:Versions = [ordered]@{
        php = Get-OptionalToolVersion -FilePath $script:PhpBinary -ArgumentList @('--version')
        phpunit = Get-OptionalToolVersion -FilePath $script:PhpBinary -ArgumentList @('vendor/phpunit/phpunit/phpunit', '--version')
        laravel = Get-OptionalToolVersion -FilePath $script:PhpBinary -ArgumentList @('artisan', '--version')
        composer = if ($composerCommand -and $composerCommand.Source) {
            Get-OptionalToolVersion -FilePath ([IO.Path]::GetFullPath($composerCommand.Source)) -ArgumentList @('--version')
        }
        else {
            'unavailable'
        }
    }

    $passwordBytes = [byte[]]::new(36)
    [Security.Cryptography.RandomNumberGenerator]::Fill($passwordBytes)
    $databasePassword = [Convert]::ToBase64String($passwordBytes)
    $script:SensitiveValues += $databasePassword

    $script:Stage = 'temporary_credentials'
    $script:TemporaryDirectory = New-SecureTemporaryDirectory -Nonce $script:Nonce
    $environmentFile = Join-Path $script:TemporaryDirectory 'postgres.env'
    $script:WorkerRegistry = Join-Path $script:TemporaryDirectory 'worker-pids.txt'
    [IO.File]::WriteAllLines(
        $environmentFile,
        @(
            'POSTGRES_DB=postgres'
            ('POSTGRES_USER=' + $databaseUser)
            ('POSTGRES_PASSWORD=' + $databasePassword)
        ),
        [Text.UTF8Encoding]::new($false)
    )
    [IO.File]::WriteAllText($script:WorkerRegistry, '', [Text.UTF8Encoding]::new($false))

    $credentialLines = @(Get-Content -LiteralPath $environmentFile)

    if ($credentialLines.Count -ne 3 -or
        @($credentialLines | Where-Object { $_ -match '^POSTGRES_(DB|USER|PASSWORD)=.+$' }).Count -ne 3) {
        throw 'credential_file_contract_invalid'
    }

    $script:Stage = 'network_create'
    $network = Invoke-RequiredProcess -FilePath $script:DockerBinary -ArgumentList @(
        'network', 'create',
        '--driver', 'bridge',
        '--label', 'emaks.rel4g.scope=wp0a',
        '--label', ('emaks.rel4g.nonce=' + $script:Nonce),
        $script:NetworkName
    ) -TimeoutSeconds 30
    $networkId = $network.StdOut.Trim()

    if ($networkId -notmatch '^[a-f0-9]{64}$') {
        throw 'network_id_invalid'
    }

    $script:NetworkId = $networkId

    $script:Stage = 'container_create'
    $container = Invoke-RequiredProcess -FilePath $script:DockerBinary -ArgumentList @(
        'run', '--detach',
        '--pull', 'never',
        '--name', $script:ContainerName,
        '--network', $script:NetworkName,
        '--label', 'emaks.rel4g.scope=wp0a',
        '--label', ('emaks.rel4g.nonce=' + $script:Nonce),
        '--mount', 'type=tmpfs,destination=/var/lib/postgresql/data,tmpfs-size=536870912,tmpfs-mode=0700',
        '--publish', '127.0.0.1:0:5432',
        '--env-file', $environmentFile,
        '--health-cmd', 'pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB"',
        '--health-interval', '1s',
        '--health-timeout', '3s',
        '--health-retries', '60',
        '--health-start-period', '2s',
        'postgres:16-alpine'
    ) -TimeoutSeconds 60
    $containerId = $container.StdOut.Trim()

    if ($containerId -notmatch '^[a-f0-9]{64}$') {
        throw 'container_id_invalid'
    }

    $script:ContainerId = $containerId

    Assert-ContainerCredentialEnvironment
    $script:Stage = 'container_health'
    Wait-EphemeralDatabaseHealthy -ContainerId $script:ContainerId
    Assert-InContainerPostgreSqlConnection
    $script:Stage = 'disposable_database_create'
    New-ExactDisposableDatabase -DatabaseName $databaseName
    $dynamicPort = Assert-EphemeralDockerIsolation

    $childEnvironment = [ordered]@{
        APP_ENV = 'testing'
        APP_DEBUG = 'false'
        LOG_CHANNEL = 'null'
        DB_CONNECTION = 'pgsql'
        DB_HOST = '127.0.0.1'
        DB_PORT = [string] $dynamicPort
        DB_DATABASE = $databaseName
        DB_USERNAME = $databaseUser
        DB_PASSWORD = $databasePassword
        PGHOST = '127.0.0.1'
        PGPORT = [string] $dynamicPort
        PGDATABASE = $databaseName
        PGUSER = $databaseUser
        PGPASSWORD = $databasePassword
        DB_URL = ''
        DATABASE_URL = ''
        CACHE_STORE = 'array'
        SESSION_DRIVER = 'array'
        QUEUE_CONNECTION = 'sync'
        MAIL_MAILER = 'array'
        BROADCAST_CONNECTION = 'null'
        PULSE_ENABLED = 'false'
        TELESCOPE_ENABLED = 'false'
        NIGHTWATCH_ENABLED = 'false'
        REL4G_CONTAINER_ID = $script:ContainerId
        REL4G_CONTAINER_NAME = $script:ContainerName
        REL4G_NETWORK_ID = $script:NetworkId
        REL4G_NETWORK_NAME = $script:NetworkName
        REL4G_NONCE = $script:Nonce
        REL4G_SCOPE = 'wp0a'
        REL4G_DOCKER_BINARY = $script:DockerBinary
        REL4G_WORKER_PID_REGISTRY = $script:WorkerRegistry
    }

    $script:Stage = 'prebootstrap_guard'
    $preflight = Invoke-RequiredProcess -FilePath $script:PhpBinary -ArgumentList @(
        ([IO.Path]::Combine($projectRoot, 'tests', 'Support', 'TechnicalServiceDecisionRaceWorker.php')),
        'preflight',
        $script:Nonce
    ) -TimeoutSeconds 30 -Environment $childEnvironment -RemoveEnvironment $removedDatabaseVariables
    $preflightPayload = $preflight.StdOut.Trim() | ConvertFrom-Json

    if (-not $preflightPayload.ok -or $preflightPayload.mode -ne 'preflight' -or -not $preflightPayload.outbound_guarded) {
        throw 'prebootstrap_guard_failed'
    }

    $script:Stage = 'host_postgres_readiness'
    Wait-HostPostgreSqlReady -Environment $childEnvironment -RemoveEnvironment $removedDatabaseVariables -Port $dynamicPort

    $script:Stage = 'migration'
    $migrationResult = Invoke-BoundedProcess -FilePath $script:PhpBinary -ArgumentList @(
        'artisan',
        'migrate',
        '--database=pgsql',
        '--force',
        '--no-interaction'
    ) -TimeoutSeconds 300 -Environment $childEnvironment -RemoveEnvironment $removedDatabaseVariables

    if ($migrationResult.ExitCode -ne 0) {
        $migrationText = $migrationResult.StdOut + "`n" + $migrationResult.StdErr
        $migrationClass = 'runtime'

        if ($migrationText -match '(?i)connection refused|could not connect|connection timed out') {
            $migrationClass = 'connection'
        }
        elseif ($migrationText -match '(?i)password authentication failed|authentication failed') {
            $migrationClass = 'authentication'
        }
        elseif ($migrationText -match '(?i)could not find driver') {
            $migrationClass = 'driver'
        }
        elseif ($migrationText -match '(?i)SQLSTATE\[([0-9A-Z]+)\]') {
            $migrationClass = 'sqlstate_' + $Matches[1].ToLowerInvariant()
        }
        elseif ($migrationText -match '(?i)syntax error|undefined table|undefined column|duplicate table|duplicate column') {
            $migrationClass = 'schema'
        }

        if ($migrationClass -eq 'sqlstate_08006') {
            $databaseState = (Invoke-RequiredProcess -FilePath $script:DockerBinary -ArgumentList @(
                'container', 'inspect', '--format',
                '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}|{{.State.ExitCode}}|{{.State.OOMKilled}}',
                $script:ContainerId
            ) -TimeoutSeconds 10).StdOut.Trim().Replace('|', '_')
            $migrationClass += '_container_' + $databaseState
        }

        $script:Stage = 'migration_' + $migrationClass
        throw 'migration_failed'
    }

    $script:Stage = 'focused_test'
    $junitFile = $script:EvidenceJunitFile
    $testSelectionArguments = Resolve-TestSelectionArguments
    $phpUnitArguments = @(
        'vendor/phpunit/phpunit/phpunit',
        '--no-configuration',
        '--bootstrap', 'tests/bootstrap.php',
        '--colors=never',
        '--do-not-cache-result',
        '--log-junit', $junitFile,
        '--fail-on-warning',
        '--fail-on-risky',
        '--fail-on-skipped',
        '--fail-on-incomplete'
    ) + $testSelectionArguments
    $script:TestCommand = [ordered]@{
        executable = $script:PhpBinary
        arguments = $phpUnitArguments
        test_paths = @($TestPath)
        filter = $Filter
        exclude_groups = @($ExcludeGroup)
        timeout_seconds = $TestTimeoutSeconds
    }
    Write-RedactedEvidenceText -Path $script:EvidenceStdOutFile -Text ''
    Write-RedactedEvidenceText -Path $script:EvidenceStdErrFile -Text ''
    Write-RedactedEvidenceText -Path $script:EvidenceJunitFile -Text ''
    Write-RedactedEvidenceText -Path $script:EvidenceFailureSummaryFile -Text 'RUN_IN_PROGRESS'
    Write-PostgreSqlContainerEvidence -Phase 'before_test'
    $preliminaryResult = [ordered]@{
        schema_version = 1
        run_id = $script:Nonce
        started_at_utc = $script:RunStartedAtUtc
        stage = 'focused_test_running'
        result = 'running'
        exit_code = $null
        cleanup = 'pending'
        command = $script:TestCommand
        versions = $script:Versions
        database = [ordered]@{
            engine = 'postgresql'
            major_version = 16
            name = $script:DatabaseName
            container_id = $script:ContainerId
            canonical_uat_connection = 0
            canonical_uat_write = 0
        }
        postgresql_diagnostics = $script:PostgreSqlEvidence
    }
    Write-RedactedEvidenceText -Path $script:EvidenceResultFile -Text ($preliminaryResult | ConvertTo-Json -Depth 12)

    try {
        $testResult = Invoke-BoundedProcess -FilePath $script:PhpBinary -ArgumentList $phpUnitArguments -TimeoutSeconds $TestTimeoutSeconds -Environment $childEnvironment -RemoveEnvironment $removedDatabaseVariables -StdOutPath $script:EvidenceStdOutFile -StdErrPath $script:EvidenceStdErrFile
    }
    catch {
        foreach ($path in @($script:EvidenceStdOutFile, $script:EvidenceStdErrFile)) {
            if (-not (Test-Path -LiteralPath $path)) {
                Write-RedactedEvidenceText -Path $path -Text ''
            }
        }

        Protect-EvidenceFile -Path $script:EvidenceJunitFile
        Write-RedactedEvidenceText -Path $script:EvidenceFailureSummaryFile -Text (Get-FirstExceptionSummary)
        $runnerClass = if ($_.Exception.Message -in @('bounded_process_start_failed', 'bounded_process_timeout')) {
            $_.Exception.Message
        }
        else {
            $_.Exception.GetType().Name
        }
        $script:Stage = 'focused_test_runner_' + $runnerClass
        throw
    }

    $script:Stage = 'focused_test_result_received'
    $testExitCode = [int] $testResult.ExitCode
    $script:TestExitCode = $testExitCode
    $script:Stage = 'focused_test_exit_' + $testExitCode
    Protect-EvidenceFile -Path $script:EvidenceJunitFile

    if ($testExitCode -ne 0) {
        $testText = $testResult.StdOut + "`n" + $testResult.StdErr
        Write-RedactedEvidenceText -Path $script:EvidenceFailureSummaryFile -Text (Get-FirstExceptionSummary)
        $knownMethods = @(
            'test_profile_rejects_canonical_port_and_non_test_database_name',
            'test_profile_uses_postgresql_16_on_dynamic_loopback_port',
            'test_ephemeral_database_has_no_persistent_volume_or_shared_network',
            'test_two_independent_php_processes_reach_the_same_controlled_barrier',
            'test_cleanup_guard_rejects_wrong_container_id_name_or_label'
        )
        $failedMethods = @($knownMethods | Where-Object { $testText.Contains($_, [StringComparison]::Ordinal) })
        $failureClass = 'unknown'

        foreach ($candidate in @('ProcessTimedOutException', 'PDOException', 'QueryException', 'RuntimeException', 'TypeError', 'JsonException', 'AssertionFailedError')) {
            if ($testText.Contains($candidate, [StringComparison]::Ordinal)) {
                $failureClass = $candidate
                break
            }
        }

        $guardMatches = @([regex]::Matches($testText, 'rel4g_(?:guard|cleanup_guard):[a-z0-9_]+') | ForEach-Object { $_.Value } | Sort-Object -Unique)
        Write-Output ('FOCUSED_FAILURE_METHODS: ' + $(if ($failedMethods.Count -eq 0) { 'unresolved' } else { $failedMethods -join ',' }))
        Write-Output ('FOCUSED_FAILURE_CLASS: ' + $failureClass)
        Write-Output ('FOCUSED_FAILURE_GUARDS: ' + $(if ($guardMatches.Count -eq 0) { 'none' } else { $guardMatches -join ',' }))
        $script:Stage = 'focused_test_' + $failureClass
        throw 'focused_test_failed'
    }

    Write-RedactedEvidenceText -Path $script:EvidenceFailureSummaryFile -Text 'NO_TEST_FAILURE'

    $script:Stage = 'focused_test_summary'

    if (-not (Test-Path -LiteralPath $junitFile)) {
        throw 'focused_test_summary_unavailable'
    }

    [xml] $junit = Get-Content -LiteralPath $junitFile -Raw
    $suites = @($junit.SelectNodes('//testsuite[not(testsuite)]'))

    if ($suites.Count -eq 0) {
        throw 'focused_test_summary_invalid'
    }

    $testCount = 0
    $assertionCount = 0
    $errorCount = 0
    $failureCount = 0
    $skippedCount = 0

    foreach ($suite in $suites) {
        $testCount += [int] $suite.GetAttribute('tests')
        $assertionCount += [int] $suite.GetAttribute('assertions')
        $errorCount += [int] $suite.GetAttribute('errors')
        $failureCount += [int] $suite.GetAttribute('failures')
        $skippedCount += [int] $suite.GetAttribute('skipped')
    }

    if ($testCount -lt 1 -or $errorCount -ne 0 -or $failureCount -ne 0 -or $skippedCount -ne 0) {
        throw 'focused_test_count_mismatch'
    }

    $script:Stage = 'post_test_scope'
    Assert-GitScope
}
catch {
    $runFailed = $true
    $script:FailureMessage = $_.Exception.Message
}
finally {
    Write-PostgreSqlContainerEvidence -Phase 'before_cleanup'
    Invoke-ExactCleanup
}

if (-not $runFailed -and -not $script:CleanupFailed) {
    try {
        $canonicalAfter = Get-CanonicalContainerSnapshot

        if (($canonicalBefore | ConvertTo-Json -Compress) -ne ($canonicalAfter | ConvertTo-Json -Compress)) {
            throw 'canonical_container_changed'
        }

        Assert-GitScope
    }
    catch {
        $runFailed = $true
    }
}

if ($script:EvidenceDirectory) {
    try {
        foreach ($path in @($script:EvidenceStdOutFile, $script:EvidenceStdErrFile)) {
            if (-not (Test-Path -LiteralPath $path)) {
                Write-RedactedEvidenceText -Path $path -Text ''
            }
        }

        Protect-EvidenceFile -Path $script:EvidenceJunitFile

        if (-not (Test-Path -LiteralPath $script:EvidenceFailureSummaryFile)) {
            Write-RedactedEvidenceText -Path $script:EvidenceFailureSummaryFile -Text (Get-FirstExceptionSummary)
        }

        $resultPayload = [ordered]@{
            schema_version = 1
            run_id = $script:Nonce
            started_at_utc = $script:RunStartedAtUtc
            finished_at_utc = [DateTime]::UtcNow.ToString('o')
            stage = $script:Stage
            result = if ($runFailed -or $script:CleanupFailed) { 'failed' } else { 'passed' }
            exit_code = $script:TestExitCode
            cleanup = if ($script:CleanupFailed) { 'failed' } else { 'complete' }
            failure_message = Protect-EvidenceText -Text ([string] $script:FailureMessage)
            command = $script:TestCommand
            versions = $script:Versions
            database = [ordered]@{
                engine = 'postgresql'
                major_version = 16
                name = $script:DatabaseName
                container_id = $script:ContainerId
                container_name = $script:ContainerName
                network_id = $script:NetworkId
                network_name = $script:NetworkName
                canonical_uat_connection = 0
                canonical_uat_write = 0
            }
            postgresql_diagnostics = $script:PostgreSqlEvidence
            evidence = [ordered]@{
                stdout = 'stdout.log'
                stderr = 'stderr.log'
                junit = if (Test-Path -LiteralPath $script:EvidenceJunitFile) { 'junit.xml' } else { $null }
                failure_summary = 'failure-summary.txt'
                postgresql_state = 'postgresql-state.json'
                postgresql_inspect_state = 'postgresql-inspect-state.json'
                postgresql_pg_isready = 'postgresql-pg-isready.txt'
                postgresql_container_log = 'postgresql-container.log'
                redacted_before_persistence = $true
            }
            failed_tests = @(Get-JunitFailureRecords)
        }
        Write-RedactedEvidenceText -Path $script:EvidenceResultFile -Text ($resultPayload | ConvertTo-Json -Depth 12)
    }
    catch {
        $runFailed = $true
        $script:Stage = 'evidence_write_failed'
        $script:FailureMessage = $_.Exception.Message
    }
}

$runEvidence = [ordered]@{
    run_id = $script:Nonce
    database_name = $script:DatabaseName
    created_at = $script:RunStartedAtUtc
    test_exit_code = $script:TestExitCode
    cleanup = if ($script:CleanupFailed) { 'failed' } else { 'complete' }
    result = if ($runFailed -or $script:CleanupFailed) { 'failed' } else { 'passed' }
}
Write-Output ('RUN_EVIDENCE: ' + ($runEvidence | ConvertTo-Json -Compress))

if ($script:EvidenceDirectory) {
    Write-Output ('EVIDENCE_DIRECTORY: ' + $script:EvidenceDirectory)
}

if ($runFailed -or $script:CleanupFailed) {
    if ($script:EvidenceFailureSummaryFile -and (Test-Path -LiteralPath $script:EvidenceFailureSummaryFile)) {
        Write-Output 'FIRST_EXCEPTION_BEGIN'
        Get-Content -LiteralPath $script:EvidenceFailureSummaryFile | Write-Output
        Write-Output 'FIRST_EXCEPTION_END'
    }

    foreach ($stream in @(
        [ordered]@{ Name = 'STDOUT'; Path = $script:EvidenceStdOutFile },
        [ordered]@{ Name = 'STDERR'; Path = $script:EvidenceStdErrFile }
    )) {
        Write-Output ($stream.Name + '_TAIL_BEGIN')

        if ($stream.Path -and (Test-Path -LiteralPath $stream.Path)) {
            Get-Content -LiteralPath $stream.Path -Tail 200 | Write-Output
        }

        Write-Output ($stream.Name + '_TAIL_END')
    }

    Write-Output ('HARNESS: FAIL stage=' + $script:Stage)
    Write-Output ('CLEANUP: ' + $(if ($script:CleanupFailed) { 'FAIL' } else { 'PASS' }))
    exit 1
}

Write-Output 'MIGRATION: PASS'
Write-Output ('FOCUSED_TESTS: PASS tests=' + $testCount + ' assertions=' + $assertionCount)
Write-Output 'CLEANUP: PASS container=absent network=absent workers=absent temp=absent'
Write-Output 'DECISION: LOCAL_REL4G_WP0A_POSTGRES_HARNESS_IMPLEMENTED_VALIDATION_PASS'
exit 0
