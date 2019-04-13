
function paginationLinksChange(triggerItem) {
    // find the search filter
    var filters = jQuery('input[name=searchFilter]');
    if (filters === undefined || filters === null || filters.length === 0) {
        alert("No search filters found");
        return;
    }

    var rowThatChanged = jQuery(triggerItem).parentsUntil('.tablenav').parent();
    var rowsPerPage = jQuery(rowThatChanged).find('select[name=perpage]').val();
    var filterText = jQuery(rowThatChanged).find('input[name=searchFilter]').val();
    
    var oldPaginationInputs = jQuery('.abj404-pagination-right input, .abj404-pagination-right select');
    var oldPaginationTable = jQuery('.abj404-pagination-right');
    var oldTable = jQuery('.wp-list-table, .wp-list-table input');
    var oldTableAR = jQuery('.wp-list-table .alternate'); // alternate rows
    var originalAlternateRowColor = jQuery(oldTableAR[0]).css('background-color');
    
    // get the URL from the html page.
    var url = jQuery(".abj404-pagination-right").attr("data-pagination-ajax-url");
    
    var gray = 'gray';
    var wpbgcolor = '#f1f1f1'; // wordpress background color

    // do an ajax call to update the data
    jQuery.ajax({
        url: url,
        type: 'POST',
        dataType: "json",
        data: {
            rowsPerPage: rowsPerPage, 
            filterText: filterText
        },
        success: function (result) {
            // replace the tables
            jQuery('.abj404-pagination-right').replaceWith(result.paginationLinks);
            jQuery('.wp-list-table').replaceWith(result.table);

            // make them gray immediately as if they were always gray.
            jQuery('.abj404-pagination-right input, .abj404-pagination-right select').css("background-color", gray);
            jQuery('.abj404-pagination-right').css("background-color", gray);
            jQuery('.wp-list-table, .wp-list-table input').css("background-color", gray);
            jQuery('.wp-list-table .alternate').css("background-color", gray);
            
            // fade them back to their normal colors.
            jQuery('.abj404-pagination-right').animate({backgroundColor: wpbgcolor});
            jQuery('.abj404-pagination-right input, .abj404-pagination-right select')
                    .animate({backgroundColor: "white"});
            jQuery('.wp-list-table, .wp-list-table input').animate({backgroundColor: "white"});
            jQuery('.wp-list-table .alternate').animate({backgroundColor: originalAlternateRowColor});
        },
        error: function (jqXHR, textStatus, errorThrown) {
            alert("Ajax error. Result: " + JSON.stringify(textStatus, null, 2) + 
                    ", error: " + JSON.stringify(errorThrown, null, 2));
        }
    });

    // we do the animation after the ajax request so that it's happening while the server is thinking.
    oldPaginationInputs.animate({backgroundColor: gray}, 3000);
    oldPaginationTable.animate({backgroundColor: gray}, 3000);
    oldTable.animate({backgroundColor: gray}, 3000);
    oldTableAR.animate({backgroundColor: gray}, 3000);
}

