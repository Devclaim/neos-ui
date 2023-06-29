import debounce from 'lodash.debounce';
import DecoupledEditor from '@ckeditor/ckeditor5-editor-decoupled/src/decouplededitor';
import {actions} from '@neos-project/neos-ui-redux-store';
import {cleanupContentBeforeCommit} from './cleanupContentBeforeCommit'

let currentEditor = null;
let editorConfig = {};

// We cache the "formattingUnderCursor"; to only emit events when it really changed.
// As there is only a single cursor active at any given time, it is safe to do this caching here inside the singleton object.
let lastFormattingUnderCursorSerialized = '';

// We get the state of all commands from CKE5 and serialize it into "formattingUnderCursor"
const handleUserInteractionCallback = () => {
    if (!currentEditor) {
        return;
    }
    const formattingUnderCursor = {};
    [...currentEditor.commands].forEach(commandTuple => {
        const [commandName, command] = commandTuple;
        if (command.value !== undefined) {
            formattingUnderCursor[commandName] = command.value;
        }
    });

    const formattingUnderCursorSerialized = JSON.stringify(formattingUnderCursor);
    if (formattingUnderCursorSerialized !== lastFormattingUnderCursorSerialized) {
        editorConfig.setFormattingUnderCursor(formattingUnderCursor);
        lastFormattingUnderCursorSerialized = formattingUnderCursorSerialized;
    }
};

export const bootstrap = _editorConfig => {
    editorConfig = _editorConfig;
};

export const createEditor = store => async options => {
    const {propertyDomNode, propertyName, editorOptions, globalRegistry, userPreferences, onChange} = options;
    const ckEditorConfig = editorConfig.configRegistry.getCkeditorConfig({
        editorOptions,
        userPreferences,
        globalRegistry,
        propertyDomNode
    });

    const isInline = editorOptions?.isInline === true ||
        propertyDomNode.tagName === 'SPAN' ||
        propertyDomNode.tagName === 'H1' ||
        propertyDomNode.tagName === 'H2' ||
        propertyDomNode.tagName === 'H3' ||
        propertyDomNode.tagName === 'H4' ||
        propertyDomNode.tagName === 'H5' ||
        propertyDomNode.tagName === 'H6' ||
        propertyDomNode.tagName === 'P';

    return DecoupledEditor
        .create(propertyDomNode, ckEditorConfig, isInline)
        .then(editor => {
            editor.ui.focusTracker.on('change:isFocused', event => {
                if (event.source.isFocused) {
                    currentEditor = editor;
                    editorConfig.setCurrentlyEditedPropertyName(propertyName);
                    handleUserInteractionCallback();
                }
            });

            editor.keystrokes.set('Ctrl+K', (_, cancel) => {
                store.dispatch(actions.UI.ContentCanvas.toggleLinkEditor());
                cancel();
            });

            // We attach all options for this editor to the editor DOM node, so it would be easier to access them from CKE plugins
            editor.neos = options;

            editor.model.document.on('change', () => handleUserInteractionCallback());
            editor.model.document.on('change:data', debounce(() => onChange(cleanupContentBeforeCommit(editor.getData())), 500, {maxWait: 5000}));
            return editor;
        }).catch(e => {
            if (e instanceof TypeError && e.message.match(/Class constructor .* cannot be invoked without 'new'/)) {
                console.error('Neos.Ui: Youre probably using a CKeditor plugin which needs to be rebuild.\nsee https://github.com/neos/neos-ui/issues/3287\n\nOriginal Error:\n\n' + e.stack);
            } else {
                console.error(e);
            }
        });
};

export const executeCommand = (command, argument, reFocusEditor = true) => {
    currentEditor.execute(command, argument);
    if (reFocusEditor) {
        currentEditor.editing.view.focus();
    }
};
