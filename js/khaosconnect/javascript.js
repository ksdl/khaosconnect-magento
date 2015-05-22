function submitForm(url) {
    $('edit_form').writeAttribute('action', url);
    $('edit_form').request({
         onComplete: function()  { 
             window.location.href = document.URL; 
         }
     })  
}