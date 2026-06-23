/**
 * Helpers for the cross content repository reference SelectBox editor.
 *
 * The data source returns options whose `value` is a `CrossContentRepositoryReference`
 * DTO ({contentRepositoryId, nodeAggregateId}). The persisted property value is the
 * same DTO (normalized to a plain object on the client). Because the SelectBox
 * compares option values and the selected value by strict identity, object DTOs
 * would never match. We therefore canonicalize every reference to a string key
 * (`contentRepositoryId` + delimiter + `nodeAggregateId`) for comparison and
 * mapping back to the original object when committing.
 */

export type CrossContentRepositoryReference = {
    contentRepositoryId: string;
    nodeAggregateId: string;
};

export type RawReferenceOption = {
    label: string;
    value: CrossContentRepositoryReference;
    nodeType: string;
    preview?: string | null;
    icon?: string;
    secondaryLabel?: string;
    tertiaryLabel?: string;
    group?: string;
    disabled?: boolean;
};

export type SelectBoxOption = {
    value: string;
    label: string;
    icon?: string;
    preview?: string | null;
    secondaryLabel?: string;
    tertiaryLabel?: string;
    group?: string;
    disabled?: boolean;
    /**
     * The node type name of the referenced node, used to resolve an icon
     * client-side via the Neos node types registry.
     */
    nodeType?: string;
    /**
     * The original reference object, kept so we can commit the object form
     * (which is what the property type expects) instead of the canonical key.
     */
    reference: CrossContentRepositoryReference;
};

type I18nRegistry = {
    translate: (
        id?: string,
        fallback?: string,
        params?: Record<string, unknown> | string[],
        packageKey?: string,
        sourceName?: string,
    ) => string;
};

const KEY_DELIMITER = '||';

/**
 * Build a stable string key from a reference value.
 *
 * Accepts the normalized object form ({contentRepositoryId, nodeAggregateId}),
 * a JSON string containing that object, or an object exposing `__identity`
 * (which is not used by our DTO but kept for robustness).
 */
export const toCanonicalKey = (value: unknown): string | null => {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    let reference: CrossContentRepositoryReference | null = null;

    if (typeof value === 'string') {
        reference = parseReferenceString(value);
    } else if (typeof value === 'object') {
        const maybe = value as Record<string, unknown>;
        if (typeof maybe.contentRepositoryId === 'string' && typeof maybe.nodeAggregateId === 'string') {
            reference = { contentRepositoryId: maybe.contentRepositoryId, nodeAggregateId: maybe.nodeAggregateId };
        }
    }

    if (reference === null) {
        return null;
    }
    return `${reference.contentRepositoryId}${KEY_DELIMITER}${reference.nodeAggregateId}`;
};

const parseReferenceString = (value: string): CrossContentRepositoryReference | null => {
    // Fast path: our canonical key itself.
    if (value.includes(KEY_DELIMITER)) {
        const [contentRepositoryId, nodeAggregateId] = value.split(KEY_DELIMITER);
        if (contentRepositoryId && nodeAggregateId) {
            return { contentRepositoryId, nodeAggregateId };
        }
    }
    try {
        const decoded = JSON.parse(value);
        if (decoded && typeof decoded === 'object') {
            const maybe = decoded as Record<string, unknown>;
            if (typeof maybe.contentRepositoryId === 'string' && typeof maybe.nodeAggregateId === 'string') {
                return { contentRepositoryId: maybe.contentRepositoryId, nodeAggregateId: maybe.nodeAggregateId };
            }
        }
    } catch {
        // not JSON - ignore
    }
    return null;
};

export const shouldDisplaySearchBox = (
    options: { minimumResultsForSearch: number },
    processedSelectBoxOptions: SelectBoxOption[],
): boolean =>
    options.minimumResultsForSearch >= 0 && processedSelectBoxOptions.length >= options.minimumResultsForSearch;

// Currently, we're doing an extremely simple lowercase substring matching; of course this could be improved a lot!
export const searchOptions = (searchTerm: string, processedSelectBoxOptions: SelectBoxOption[]): SelectBoxOption[] =>
    processedSelectBoxOptions.filter(
        (option) => option.label && option.label.toLowerCase().indexOf(searchTerm.toLowerCase()) !== -1,
    );

/**
 * Convert the raw data source options (an array of ReferenceOption DTOs) into
 * the SelectBox option format, using the canonical key as `value`.
 *
 * `resolveNodeTypeIcon` is an optional callback used to resolve a node-type
 * icon from the Neos node types registry; when provided, the resulting icon is
 * set on each option so the preview element can render it without needing
 * access to the registry itself.
 *
 * If the currently selected value is not present in the data source, a
 * placeholder "invalid value" option is appended so the SelectBox can still
 * display it (mirrors the behaviour of the core SelectBox editor).
 */
export const processSelectBoxOptions = (
    i18nRegistry: I18nRegistry,
    selectBoxOptions: RawReferenceOption[],
    currentValue: string | string[],
    resolveNodeTypeIcon?: (nodeTypeName: string) => string | undefined,
): SelectBoxOption[] => {
    const processedSelectBoxOptions: SelectBoxOption[] = [];
    const validValues: Record<string, true> = {};

    for (const selectBoxOption of selectBoxOptions) {
        if (!selectBoxOption || !selectBoxOption.label) {
            continue;
        }
        const key = toCanonicalKey(selectBoxOption.value);
        if (key === null) {
            continue;
        }

        const icon =
            selectBoxOption.icon ?? (resolveNodeTypeIcon ? resolveNodeTypeIcon(selectBoxOption.nodeType) : undefined);

        const processedSelectBoxOption: SelectBoxOption = {
            value: key,
            label: i18nRegistry.translate(selectBoxOption.label, selectBoxOption.label),
            icon,
            preview: selectBoxOption.preview,
            secondaryLabel: selectBoxOption.secondaryLabel,
            tertiaryLabel: selectBoxOption.tertiaryLabel,
            disabled: selectBoxOption.disabled,
            nodeType: selectBoxOption.nodeType,
            reference: selectBoxOption.value,
        };

        if (selectBoxOption.group) {
            processedSelectBoxOption.group = i18nRegistry.translate(selectBoxOption.group, selectBoxOption.group);
        }

        validValues[key] = true;
        processedSelectBoxOptions.push(processedSelectBoxOption);
    }

    const currentValues = Array.isArray(currentValue) ? currentValue : [currentValue];
    for (const singleValue of currentValues) {
        if (singleValue === '' || singleValue === null || singleValue === undefined) {
            continue;
        }
        if (singleValue in validValues) {
            continue;
        }

        // Mismatch detected. Add a placeholder so the value is displayable.
        processedSelectBoxOptions.push({
            value: singleValue,
            label: `Invalid value: "${singleValue}"`,
            icon: 'exclamation-triangle',
            reference: { contentRepositoryId: '', nodeAggregateId: '' },
        });
    }

    return processedSelectBoxOptions;
};

/**
 * Resolve a canonical key (or array of keys) back to the original reference
 * objects using the processed options as a lookup table. Keys that cannot be
 * resolved are skipped.
 */
export const keysToReferences = (keys: string[], options: SelectBoxOption[]): CrossContentRepositoryReference[] => {
    const lookup = new Map<string, CrossContentRepositoryReference>();
    for (const option of options) {
        lookup.set(option.value, option.reference);
    }
    const references: CrossContentRepositoryReference[] = [];
    for (const key of keys) {
        const reference = lookup.get(key);
        if (reference && reference.contentRepositoryId && reference.nodeAggregateId) {
            references.push(reference);
        }
    }
    return references;
};
