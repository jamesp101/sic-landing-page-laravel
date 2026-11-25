/** Immutable list helpers shared by the inspector list editors. */
export function moveItem<T>(items: readonly T[], from: number, to: number): T[] {
    if (to < 0 || to >= items.length || from === to) return [...items];
    const next = [...items];
    const [item] = next.splice(from, 1);
    next.splice(to, 0, item as T);
    return next;
}

export function removeItem<T>(items: readonly T[], index: number): T[] {
    return items.filter((_, i) => i !== index);
}

export function replaceItem<T>(items: readonly T[], index: number, value: T): T[] {
    return items.map((item, i) => (i === index ? value : item));
}

/** A change to a list: `remove` items from `start` replaced with `insert`. */
export interface Splice<T> {
    start: number;
    remove: number;
    insert: T[];
}

/** Applies `splice`; `null` when the list would grow past `max`. */
export function spliceItems<T>(items: readonly T[], { start, remove, insert }: Splice<T>, max: number): T[] | null {
    const next = [...items.slice(0, start), ...insert, ...items.slice(start + remove)];
    return next.length > max ? null : next;
}

/** Splits an event value (`YYYY-MM-DD` or `YYYY-MM-DDTHH:mm`) into date and time parts. */
export function splitDateTime(value: string): { date: string; time: string } {
    const [date = '', time = ''] = value.split('T');
    return { date, time };
}

/** A time without a date is not stored. */
export function joinDateTime(date: string, time: string): string {
    if (!date) return '';
    return time ? `${date}T${time}` : date;
}
