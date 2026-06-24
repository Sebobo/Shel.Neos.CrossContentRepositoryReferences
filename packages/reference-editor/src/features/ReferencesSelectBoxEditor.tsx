import React, { type FC, useContext, useEffect, useMemo, useRef, useState, useCallback } from 'react';
import { useSelector, useDispatch } from 'react-redux';
import { SelectBox, MultiSelectBox } from '@neos-project/react-ui-components';
import { selectors, actions } from '@neos-project/neos-ui-redux-store';
import { translate } from '@neos-project/neos-ui-i18n';
import { NeosContext } from '@neos-project/neos-ui-decorators';
import {
    type CrossContentRepositoryReference,
    type RawReferenceOption,
    processSelectBoxOptions,
    searchOptions,
    shouldDisplaySearchBox,
    toCanonicalKey,
    keysToReferences,
} from '../helpers/optionsHelper';
import ReferenceOption from './ReferenceOption';

import style from './style.module.css';

type I18nRegistry = {
    translate: (
        id?: string,
        fallback?: string,
        params?: Record<string, unknown> | string[],
        packageKey?: string,
        sourceName?: string,
    ) => string;
};

type DataSourcesDataLoader = {
    resolveValue: (options: unknown, value: unknown) => Promise<RawReferenceOption[]>;
};

type NeosContextValue = {
    globalRegistry: {
        get: <T = unknown>(key: string) => T;
    };
};

type NodeTypesRegistry = {
    getNodeType?: (nodeTypeName: string) => { ui?: { icon?: string } } | undefined;
};

type EditorProps = {
    commit: (value: CrossContentRepositoryReference | CrossContentRepositoryReference[]) => void;
    className?: string;
    value: CrossContentRepositoryReference | CrossContentRepositoryReference[] | null;
    options: {
        allowEmpty?: boolean;
        placeholder?: string;
        disabled?: boolean;
        multiple?: boolean;
        dataSourceIdentifier?: string;
        dataSourceUri?: string;
        dataSourceDisableCaching?: boolean;
        dataSourceAdditionalData?: Record<string, unknown>;
        minimumResultsForSearch?: number;
        threshold?: number;
        values?: Record<string, RawReferenceOption>;
    };
};

const DEFAULT_OPTIONS = {
    minimumResultsForSearch: 5,
    threshold: 0,
    disabled: false,
};

const getDataLoaderOptionsForProps = (props: EditorProps, focusedNodePath: string | undefined) => ({
    contextNodePath: focusedNodePath,
    dataSourceIdentifier: props.options.dataSourceIdentifier,
    dataSourceUri: props.options.dataSourceUri,
    dataSourceAdditionalData: props.options.dataSourceAdditionalData,
    dataSourceDisableCaching: Boolean(props.options.dataSourceDisableCaching),
});

/**
 * Select box editor for cross content repository references.
 *
 * Unlike the core `DataSourceBasedSelectBoxEditor`, this editor understands that
 * both the option values and the persisted property value are
 * `CrossContentRepositoryReference` DTOs (objects, not strings). It canonicalizes
 * them to string keys for comparison and maps committed values back to the
 * original object form expected by the property type.
 *
 * Options that reference a node exposing an image property can render a small
 * thumbnail preview (the preview URI is provided by the data source).
 */
const ReferencesSelectBoxEditor: FC<EditorProps> = (props) => {
    const { commit, value, className } = props;
    const neosContext = useContext(NeosContext) as NeosContextValue | null;

    const i18nRegistry = neosContext?.globalRegistry.get<I18nRegistry | undefined>('i18n');
    const dataSourcesDataLoader = neosContext?.globalRegistry
        .get<{ get: (key: string) => DataSourcesDataLoader } | undefined>('dataLoaders')
        ?.get('DataSources');

    const focusedNodePath = useSelector((state: unknown) => {
        const nodesSelectors = (
            selectors as unknown as {
                CR: { Nodes: { focusedNodePathSelector: (state: unknown) => string } };
            }
        ).CR.Nodes;
        return nodesSelectors.focusedNodePathSelector(state);
    });

    const creationDialogIsOpen = useSelector((state: unknown) => {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const s = state as Record<string, any>;
        return s?.ui?.nodeCreationDialog?.isOpen ?? false;
    });
    const changesInInspector = useSelector((state: unknown) => {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const s = state as Record<string, any>;
        return s?.ui?.inspector?.valuesByNodePath ?? {};
    });

    const dispatch = useDispatch();

    const nodeTypesRegistry = useMemo<NodeTypesRegistry | undefined>(() => {
        try {
            return neosContext?.globalRegistry.get<NodeTypesRegistry | undefined>(
                '@neos-project/neos-ui-contentrepository',
            );
        } catch {
            return undefined;
        }
    }, [neosContext]);

    const resolveNodeTypeIcon = useCallback(
        (nodeTypeName: string): string | undefined => nodeTypesRegistry?.getNodeType?.(nodeTypeName)?.ui?.icon,
        [nodeTypesRegistry],
    );

    const options = { ...DEFAULT_OPTIONS, ...props.options };

    const [searchTerm, setSearchTerm] = useState('');
    const [isLoading, setIsLoading] = useState(false);
    const [selectBoxOptions, setSelectBoxOptions] = useState<RawReferenceOption[]>([]);

    const loadSelectBoxOptions = useCallback(() => {
        if (!dataSourcesDataLoader) {
            return;
        }
        // For static `values` options (no data source) we don't need to load.
        if (!props.options.dataSourceIdentifier && !props.options.dataSourceUri) {
            const staticValues = props.options.values ?? {};
            setSelectBoxOptions(Object.values(staticValues).filter(Boolean) as RawReferenceOption[]);
            return;
        }

        setIsLoading(true);
        dataSourcesDataLoader
            .resolveValue(getDataLoaderOptionsForProps(props, focusedNodePath), value)
            .then((loadedOptions) => {
                setIsLoading(false);
                setSelectBoxOptions(loadedOptions ?? []);
            })
            .catch(() => {
                setIsLoading(false);
            });
    }, [dataSourcesDataLoader, focusedNodePath, props, value]);

    useEffect(() => {
        loadSelectBoxOptions();
        // Initial load only - subsequent loads are handled by the effect below.
    }, []);

    const previousDataLoaderOptions = useRef<string>('');
    useEffect(() => {
        const next = JSON.stringify(getDataLoaderOptionsForProps(props, focusedNodePath));
        if (previousDataLoaderOptions.current !== next) {
            previousDataLoaderOptions.current = next;
            loadSelectBoxOptions();
        }
    }, [focusedNodePath, props, loadSelectBoxOptions]);

    const processedValue = useMemo<string | string[]>(() => {
        if (options.multiple) {
            const values = Array.isArray(value) ? value : value ? [value] : [];
            const keys = values.map((v) => toCanonicalKey(v)).filter((k): k is string => k !== null);
            return keys;
        }
        return toCanonicalKey(value) ?? '';
    }, [value, options.multiple]);

    const processedSelectBoxOptions = useMemo(() => {
        if (isLoading || !i18nRegistry) {
            return [];
        }
        return processSelectBoxOptions(i18nRegistry, selectBoxOptions, processedValue, resolveNodeTypeIcon);
    }, [isLoading, i18nRegistry, selectBoxOptions, processedValue, resolveNodeTypeIcon]);

    const handleSearchTermChange = useCallback((term: string) => {
        setSearchTerm(term);
    }, []);

    const handleSingleChange = useCallback(
        (key: string) => {
            const reference = keysToReferences([key], processedSelectBoxOptions)[0];
            if (reference) {
                commit(reference);
            } else if (key === '' || key === null) {
                commit(null as unknown as CrossContentRepositoryReference);
            }
        },
        [commit, processedSelectBoxOptions],
    );

    const handleMultiChange = useCallback(
        (keys: string[]) => {
            commit(keysToReferences(keys, processedSelectBoxOptions));
        },
        [commit, processedSelectBoxOptions],
    );

    // Navigation: when clicking an option (single-select header or multi-select item),
    // dispatch setSrc to navigate the content canvas to the node's preview URI.
    const canNavigate = !creationDialogIsOpen && !Object.keys(changesInInspector).length;

    const handleNavigate = useCallback(
        (uri: string | null | undefined) => {
            if (uri && canNavigate) {
                dispatch(actions.UI.ContentCanvas.setSrc(uri));
            }
        },
        [dispatch, canNavigate],
    );

    const handleHeaderClick = useCallback(() => {
        // Find the selected option by matching the canonicalized value.
        if (processedSelectBoxOptions.length > 0 && processedValue) {
            const selectedOption = processedSelectBoxOptions.find((option) => option.value === processedValue);
            handleNavigate(selectedOption?.uri ?? null);
        }
    }, [handleNavigate, processedSelectBoxOptions, processedValue]);

    const handleItemClick = useCallback(
        (option: { uri?: string | null }) => {
            handleNavigate(option.uri ?? null);
        },
        [handleNavigate],
    );

    if (!i18nRegistry) {
        return null;
    }

    const placeholder = options.placeholder ? i18nRegistry.translate(unescape(options.placeholder)) : undefined;
    const loadingLabel = translate('Neos.Neos:Main:loading', 'Loading');
    const noMatchesFoundLabel = translate('Neos.Neos:Main:noMatchesFound', 'No matches found');
    const searchBoxLeftToTypeLabel = translate('Neos.Neos:Main:searchBoxLeftToType', 'Please type more');

    if (options.multiple) {
        return (
            <MultiSelectBox
                className={[className, style.referenceSelectBox].join(' ')}
                options={processedSelectBoxOptions}
                values={processedValue as string[]}
                onValuesChange={handleMultiChange}
                onItemClick={handleItemClick}
                loadingLabel={loadingLabel}
                ListPreviewElement={ReferenceOption}
                displayLoadingIndicator={isLoading}
                placeholder={placeholder}
                allowEmpty={options.allowEmpty}
                displaySearchBox={shouldDisplaySearchBox(options, processedSelectBoxOptions)}
                searchOptions={searchOptions(searchTerm, processedSelectBoxOptions)}
                onSearchTermChange={handleSearchTermChange}
                noMatchesFoundLabel={noMatchesFoundLabel}
                searchBoxLeftToTypeLabel={searchBoxLeftToTypeLabel}
                threshold={options.threshold}
                disabled={options.disabled}
            />
        );
    }

    return (
        <SelectBox
            className={className}
            options={searchTerm ? searchOptions(searchTerm, processedSelectBoxOptions) : processedSelectBoxOptions}
            value={processedValue as string}
            onValueChange={handleSingleChange}
            onHeaderClick={handleHeaderClick}
            loadingLabel={loadingLabel}
            ListPreviewElement={ReferenceOption}
            displayLoadingIndicator={isLoading}
            showDropDownToggle={false}
            placeholder={placeholder}
            allowEmpty={options.allowEmpty}
            displaySearchBox={shouldDisplaySearchBox(options, processedSelectBoxOptions)}
            onSearchTermChange={handleSearchTermChange}
            noMatchesFoundLabel={noMatchesFoundLabel}
            searchBoxLeftToTypeLabel={searchBoxLeftToTypeLabel}
            threshold={options.threshold}
            disabled={options.disabled}
        />
    );
};

export default ReferencesSelectBoxEditor;
