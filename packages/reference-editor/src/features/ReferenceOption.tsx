import React, { type FC } from 'react';

import { SelectBox_Option_MultiLineWithThumbnail } from '@neos-project/react-ui-components';
import type { SelectBoxOption } from '../helpers/optionsHelper';

type ReferenceOptionProps = {
    option: SelectBoxOption;
    [key: string]: unknown;
};

/**
 * Renders a single select box option with an optional thumbnail preview
 * (for nodes that expose an image property) and an optional node-type icon.
 */
const ReferenceOption: FC<ReferenceOptionProps> = (props) => {
    const { option } = props;

    return (
        <SelectBox_Option_MultiLineWithThumbnail
            {...props}
            imageUri={option.preview ?? undefined}
            icon={option.icon}
            label={option.label}
            secondaryLabel={option.secondaryLabel}
            tertiaryLabel={option.tertiaryLabel}
        />
    );
};

export default ReferenceOption;
