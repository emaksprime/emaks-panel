import { router } from '@inertiajs/react';

export function ManagementScopeFilter({ scopes = [], activeKey, onChange, loading }) {
    return (
        <div className="flex flex-wrap gap-2">
            {scopes.map((scope) => (
                <button
                    key={scope.key}
                    type="button"
                    disabled={loading}
                    onClick={() => {
                        if (scope.navigateTo) {
                            router.visit(scope.navigateTo);
                            return;
                        }

                        onChange({ scope_key: scope.key });
                    }}
                    className={[
                        'rounded-md border px-3 py-2 text-sm font-semibold transition',
                        activeKey === scope.key
                            ? 'border-emerald-700 bg-emerald-700 text-white'
                            : 'border-slate-200 bg-white text-slate-600 hover:border-emerald-200 hover:text-emerald-700',
                    ].join(' ')}
                    title={scope.note}
                >
                    {scope.label}
                </button>
            ))}
        </div>
    );
}
