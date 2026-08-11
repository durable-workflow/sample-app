export function jsonContainerEntryCount(value) {
    if (value === null || typeof value !== 'object') {
        return null;
    }

    return Object.keys(value).length;
}
