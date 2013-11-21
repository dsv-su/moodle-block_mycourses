function toggle(showHideContent, headerCollapsed) {
    var content = document.getElementById(showHideContent);
    var header = document.getElementById(headerCollapsed);

    if (content.style.display == "block") {
        $('#'+showHideContent).slideUp();
        header.className = "c_header collapsed";
    } else {
        $('#'+showHideContent).slideDown();
        content.style.display = "block";
        header.className = "c_header expanded";
    }
}
