// Return amounts in minor currency units, preserving the exact total.
export function commissionSplit(amount, rates) {
    if (!/^\d{1,9}(\.\d{1,2})?$/.test(String(amount))) return null;
    if (rates.length !== 5 || rates.some(rate => !/^\d{1,3}(\.\d{1,2})?$/.test(String(rate)) || Number(rate) > 100)) return null;
    const points = rates.map(rate => Math.round(Number(rate) * 100));
    if (points.reduce((sum,rate) => sum + rate, 0) !== 10000) return null;
    const minor = Math.round(Number(amount) * 100);
    const parts = points.map((rate,index) => ({index, minor:Math.floor(minor*rate/10000), fraction:(minor*rate)%10000}));
    const remainder = minor - parts.reduce((sum,part) => sum + part.minor, 0);
    const ranked = [...parts].sort((a,b) => b.fraction-a.fraction || a.index-b.index);
    for (let i=0; i<remainder; i++) ranked[i].minor++;
    return parts.map(part => part.minor);
}
