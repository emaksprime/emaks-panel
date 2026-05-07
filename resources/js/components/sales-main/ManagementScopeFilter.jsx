import { router } from '@inertiajs/react';

export function ManagementScopeFilter({ scopes = [], activeKey, onChange, loading, filters = {} }) {
    return (
        <div className="flex flex-wrap gap-2">
            {scopes.map((scope) => (
                <button
                    key={scope.key}
                    type="button"
                    disabled={loading}
                    onClick={() => {
                        if (scope.navigateTo) {
                            router.visit(scope.navigateTo, {
                                data: {
                                    grain: filters.grain,
                                    date_from: filters.date_from,
                                    date_to: filters.date_to,
                                    detail_type: filters.detail_type,
                                    scope_key: scope.key,
                                },
                                preserveScroll: true,
                                preserveState: false,
                            });
                            return;
                        }

                        onChange({ scope_key: scope.key });
                    }}
                    className={[
                        'rounded-xl border px-4 py-2 text-sm font-semibold transition',
                        activeKey === scope.key
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
