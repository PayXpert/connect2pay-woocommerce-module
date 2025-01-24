document.addEventListener('DOMContentLoaded', function () {
    const copyButton = document.getElementById('copy-to-clipboard');
    const statusInfo = document.getElementById('status-info');

    copyButton.addEventListener('click', function () {
        let textToCopy = '';

        // Collect all list items and append to the text
        statusInfo.querySelectorAll('li').forEach((item) => {
            textToCopy += item.textContent.trim() + '\n';
        });

        // Use the Clipboard API to copy the text
        navigator.clipboard.writeText(textToCopy).then(() => {
            alert('Status information copied to clipboard!');
        }).catch(err => {
            console.error('Failed to copy text: ', err);
            alert('Failed to copy. Please try again.');
        });
    });
});


jQuery(document).ready(function () {
 
    jQuery(document).ready(function($) {
        const form = $("#mainform");
        if (form.length) {
            // Create a new div element
            const div = $("<div>");
            
            // Copy attributes from the form to the div
            $.each(form[0].attributes, function(index, attr) {
                div.attr(attr.name, attr.value);
            });
            
            // Move all children to the new div
            div.append(form.contents());
            
            // Replace the form with the div
            form.replaceWith(div);
        }
    });
    
    


});    


document.addEventListener('beforeunload', function(event) {
    // Prevent default behavior if needed
    event.preventDefault();
    // Alternatively, remove the event listener entirely
}, true);


