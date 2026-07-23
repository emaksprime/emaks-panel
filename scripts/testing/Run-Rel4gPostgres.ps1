[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

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

$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$allowedPaths = @(
    'scripts/testing/Run-Rel4gPostgres.ps1',
    'tests/Support/IsolatedPostgreSqlEnvironment.php',
    'tests/Support/TechnicalServiceDecisionRaceWorker.php',
    'tests/Feature/TechnicalServicePostgresIsolationTest.php'
)
$removedDatabaseVariables = @(
    'DB_URL',
    'DATABASE_URL',
    'PGHOST',
    'PGPORT',
    'PGDATABASE',
    'PGSERVICE',
    'PGSERVICEFILE'
)

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

        [string[]] $RemoveEnvironment = @()
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

        throw 'bounded_process_timeout'
    }

    $process.WaitForExit()

    return [pscustomobject]@{
        ExitCode = $process.ExitCode
        StdOut = $stdoutTask.GetAwaiter().GetResult()
        StdErr = $stderrTask.GetAwaiter().GetResult()
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

    return [IO.Path]::GetFullPath($command.Source)
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
    $observed = @(
        $status -split "`r?`n" |
            Where-Object { $_ -ne '' } |
            ForEach-Object { $_.Substring(3).Replace('\', '/') }
    )

    $unexpected = @($observed | Where-Object { $_ -notin $allowedPaths })

    if ($unexpected.Count -ne 0 -or $observed.Count -ne 4) {
        throw 'git_allowlist_changed'
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

    return [IO.Path]::GetFullPath($path)
}

function Remove-ExactTemporaryDirectory {
    param([Parameter(Mandatory)][string] $Path)

    $fullPath = [IO.Path]::GetFullPath($Path)
    $temporaryRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\') + '\'

    if (-not $fullPath.StartsWith($temporaryRoot, [StringComparison]::OrdinalIgnoreCase) -or
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

    $worker = Join-Path $projectRoot 'tests\Support\TechnicalServiceDecisionRaceWorker.php'
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

function Stop-RecordedWorkers {
    if (-not $script:WorkerRegistry -or -not (Test-Path -LiteralPath $script:WorkerRegistry)) {
        return
    }

    $workerPath = [IO.Path]::GetFullPath((Join-Path $projectRoot 'tests\Support\TechnicalServiceDecisionRaceWorker.php'))
    $entries = @(Get-Content -LiteralPath $script:WorkerRegistry | Where-Object { $_ -ne '' } | Sort-Object -Unique)

    foreach ($entry in $entries) {
        $parts = $entry.Split('|')

        if ($parts.Count -ne 3 -or $parts[0] -notmatch '^\d+$' -or $parts[1] -ne $script:Nonce -or $parts[2] -ne 'TechnicalServiceDecisionRaceWorker.php') {
            $script:CleanupFailed = $true
            continue
        }

        $pidValue = [int] $parts[0]
        $lookupErrors = @()
        $process = Get-CimInstance Win32_Process -Filter "ProcessId = $pidValue" -ErrorAction SilentlyContinue -ErrorVariable lookupErrors

        if ($lookupErrors.Count -ne 0) {
            $script:CleanupFailed = $true
            continue
        }

        if (-not $process) {
            continue
        }

        $executable = if ($process.ExecutablePath) { [IO.Path]::GetFullPath($process.ExecutablePath) } else { '' }
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
            $lookupErrors = @()
            $stillRunning = Get-CimInstance Win32_Process -Filter "ProcessId = $pidValue" -ErrorAction SilentlyContinue -ErrorVariable lookupErrors

            if ($lookupErrors.Count -ne 0) {
                $script:CleanupFailed = $true
                break
            }
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

$runFailed = $false
$testCount = 0
$assertionCount = 0

try {
    $script:GitBinary = Get-CommandPath -Name 'git'
    $script:DockerBinary = Get-CommandPath -Name 'docker'
    $script:PhpBinary = Get-CommandPath -Name 'php'
    $script:InitialHead = (Invoke-RequiredProcess -FilePath $script:GitBinary -ArgumentList @('-C', $projectRoot, 'rev-parse', 'HEAD') -TimeoutSeconds 10).StdOut.Trim()
    $script:InitialTree = (Invoke-RequiredProcess -FilePath $script:GitBinary -ArgumentList @('-C', $projectRoot, 'rev-parse', 'HEAD^{tree}') -TimeoutSeconds 10).StdOut.Trim()

    Assert-GitScope

    if (Test-Path -LiteralPath (Join-Path $projectRoot 'bootstrap\cache\config.php')) {
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
    $databaseUser = 'rel4g_' + $script:Nonce

    $passwordBytes = [byte[]]::new(36)
    [Security.Cryptography.RandomNumberGenerator]::Fill($passwordBytes)
    $databasePassword = [Convert]::ToBase64String($passwordBytes)

    $script:Stage = 'temporary_credentials'
    $script:TemporaryDirectory = New-SecureTemporaryDirectory -Nonce $script:Nonce
    $environmentFile = Join-Path $script:TemporaryDirectory 'postgres.env'
    $script:WorkerRegistry = Join-Path $script:TemporaryDirectory 'worker-pids.txt'
    [IO.File]::WriteAllLines(
        $environmentFile,
        @(
            ('POSTGRES_DB=' + $databaseName)
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
        (Join-Path $projectRoot 'tests\Support\TechnicalServiceDecisionRaceWorker.php'),
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
    $junitFile = Join-Path $script:TemporaryDirectory 'phpunit-junit.xml'
    try {
        $testResult = Invoke-BoundedProcess -FilePath $script:PhpBinary -ArgumentList @(
            'vendor/phpunit/phpunit/phpunit',
            '--no-configuration',
            '--bootstrap', 'vendor/autoload.php',
            '--colors=never',
            '--do-not-cache-result',
            '--log-junit', $junitFile,
            '--fail-on-warning',
            '--fail-on-risky',
            '--fail-on-skipped',
            '--fail-on-incomplete',
            'tests/Feature/TechnicalServicePostgresIsolationTest.php'
        ) -TimeoutSeconds 180 -Environment $childEnvironment -RemoveEnvironment $removedDatabaseVariables
    }
    catch {
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
    $script:Stage = 'focused_test_exit_' + $testExitCode

    if ($testExitCode -ne 0) {
        $testText = $testResult.StdOut + "`n" + $testResult.StdErr
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

    if ($testCount -ne 5 -or $errorCount -ne 0 -or $failureCount -ne 0 -or $skippedCount -ne 0) {
        throw 'focused_test_count_mismatch'
    }

    $script:Stage = 'post_test_scope'
    Assert-GitScope
}
catch {
    $runFailed = $true
}
finally {
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

if ($runFailed -or $script:CleanupFailed) {
    Write-Output ('HARNESS: FAIL stage=' + $script:Stage)
    Write-Output ('CLEANUP: ' + $(if ($script:CleanupFailed) { 'FAIL' } else { 'PASS' }))
    exit 1
}

Write-Output 'MIGRATION: PASS'
Write-Output ('FOCUSED_TESTS: PASS tests=' + $testCount + ' assertions=' + $assertionCount)
Write-Output 'CLEANUP: PASS container=absent network=absent workers=absent temp=absent'
Write-Output 'DECISION: LOCAL_REL4G_WP0A_POSTGRES_HARNESS_IMPLEMENTED_VALIDATION_PASS'
exit 0
