export function ManagementScopeFilter({ scopes = [], activeKey, onChange, loading }) {
    const normalizeKey = (key) => String(key ?? '').replaceAll('-', '_');
    const active = normalizeKey(activeKey);

    return (
        <div className="flex flex-wrap gap-2">
            {scopes.map((scope) => (
                <button
                    key={scope.key}
                    type="button"
                    disabled={loading}
                    onClick={() => {
                        onChange({
                            scope_key: normalizeKey(scope.key),
                            rep_code: scope.repCode ?? null,
                        });
                    }}
                    className={[
                        'rounded-xl border px-4 py-2 text-sm font-semibold transition',
                        active === normalizeKey(scope.key)
                            ? 'border-blue-700 bg-blue-700 text-white shadow-sm'
                            : 'border-slate-200 bg-white text-slate-600 hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700',
                    ].join(' ')}
                    title={scope.note}
                >
                    {scope.label}
                </button>
            ))}
        </div>
    );
}
