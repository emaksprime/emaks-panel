const accountTokenPattern = /(TEŞHİR HESABI|TESHIR HESABI|KONSİNYE HESABI|KONSINYE HESABI|KONSİNYE|KONSINYE)/giu;
const exactAccountTokenPattern = /^(TEŞHİR HESABI|TESHIR HESABI|KONSİNYE HESABI|KONSINYE HESABI|KONSİNYE|KONSINYE)$/iu;

export function HighlightedAccountLabel({ value, className = '' }) {
    const label = (value ?? '').toString();
    const parts = label.split(accountTokenPattern).filter((part) => part !== '');

    return (
        <span className={className} title={label}>
            {parts.map((part, index) => (
                exactAccountTokenPattern.test(part)
                    ? <strong key={`${part}-${index}`} className="font-bold text-slate-950">{part}</strong>
                    : <span key={`${part}-${index}`}>{part}</span>
            ))}
        </span>
    );
}
