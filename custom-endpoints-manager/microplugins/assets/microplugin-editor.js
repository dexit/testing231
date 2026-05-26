(function() {
    if ( typeof ace === 'undefined' ) {
        console.error( 'Ace editor not loaded.' );
        return;
    }

    var editor      = ace.edit( 'micropluginEditor' );
    var PhpMode     = ace.require( 'ace/mode/php' ).Mode;
    var selectTheme = document.getElementById( 'micropluginEditorThemeSelect' );
    var fontSize    = document.getElementById( 'micropluginEditorFontSize' );
    var postContent = document.getElementById( 'postContent' );

    if ( ! editor || ! selectTheme || ! fontSize || ! postContent ) {
        console.error( 'Ace editor: required elements not found.' );
        return;
    }

    editor.session.setMode( new PhpMode() );
    editor.session.setOption( 'useWorker', false );
    editor.setShowPrintMargin( false );
    editor.setValue( postContent.value );
    editor.clearSelection();

    // Attach PHP error annotations from hidden divs
    var phpErrors   = document.getElementsByClassName( 'php-error' );
    var annotations = [];

    for ( var i = 0; i < phpErrors.length; i++ ) {
        var err  = phpErrors[ i ];
        var type = parseInt( err.getAttribute( 'data-type' ), 10 );
        var line = parseInt( err.getAttribute( 'data-line' ), 10 ) - 1;

        var fatalTypes = [ 1, 4, 16, 32, 64, 128 ];
        annotations.push({
            row    : line,
            column : 0,
            text   : err.getAttribute( 'data-message' ),
            type   : fatalTypes.indexOf( type ) !== -1 ? 'error' : 'warning',
        });
    }
    editor.getSession().setAnnotations( annotations );

    // Sync editor content back to the hidden textarea
    editor.on( 'change', function() {
        postContent.value = editor.getValue();
    });

    function applyTheme() {
        if ( selectTheme.value ) {
            editor.setTheme( selectTheme.value );
        }
    }

    function applyFontSize() {
        if ( fontSize.value ) {
            editor.setFontSize( parseInt( fontSize.value, 10 ) );
        }
    }

    selectTheme.onchange = applyTheme;
    fontSize.onchange    = applyFontSize;

    applyTheme();
    applyFontSize();
})();
