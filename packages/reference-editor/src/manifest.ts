import manifest from '@neos-project/neos-ui-extensibility';
import ReferencesSelectBoxEditor from './features/ReferencesSelectBoxEditor';

manifest('Shel.Neos.CrossContentRepositoryReferences', {}, (globalRegistry) => {
    const editorsRegistry = globalRegistry.get('inspector').get('editors');

    editorsRegistry.set('Shel.Neos.CrossContentRepositoryReferences/Inspector/Editors/SelectBoxEditor', {
        component: ReferencesSelectBoxEditor,
    });
});
