function toggle(showHideContent, headerCollapsed) {
    var content = document.getElementById(showHideContent);
    var header = document.getElementById(headerCollapsed);

    if (content.style.display == 'block') {
        $('#'+showHideContent).slideUp('fast');
        header.className = 'c_header collapsed';
    } else {
        $('#'+showHideContent).slideDown('fast');
        content.style.display = 'block';
        header.className = 'c_header expanded';
    }
}
