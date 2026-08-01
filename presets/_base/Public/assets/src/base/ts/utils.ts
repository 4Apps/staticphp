export class Utils {
    static inputEscape(text: string) {
        return text.replace(/"/g, '&quot;');
    }

    static escapeTextareaValue(text: string) {
        if (typeof text === 'undefined' || typeof text === 'number' || text === null) {
            return text;
        }
        return text.replace(/\r\n|\r|\n/gm, '&#013;&#010;');
    }
}
