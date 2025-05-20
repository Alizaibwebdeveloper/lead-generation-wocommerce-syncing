jQuery(document).ready(function ($) {
    // Hide step2 initially
    $('#step2').hide();

    // Next button: Show step2, hide step1
    $('.wc-lead-next').click(function (e) {
        e.preventDefault();
        $('#step1').removeClass('active').hide();
        $('#step2').addClass('active').show();
    });

    // Previous button: Show step1, hide step2
    $('.wc-lead-prev').click(function (e) {
        e.preventDefault();
        $('#step2').removeClass('active').hide();
        $('#step1').addClass('active').show();
    });

    // Ensure form submission is not blocked
    $('#wc-lead-form').on('submit', function (e) {
        // Allow form submission to proceed
        return true;
    });
});