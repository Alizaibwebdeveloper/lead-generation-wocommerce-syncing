jQuery(document).ready(function ($) {
    $('.wc-lead-next').click(function () {
        var currentStep = $(this).closest('.wc-lead-step');
        var nextStep = currentStep.next('.wc-lead-step');

        // Validate current step before proceeding
        var isValid = true;
        currentStep.find('[required]').each(function () {
            if (!$(this).val()) {
                isValid = false;
                $(this).addClass('error');
            } else {
                $(this).removeClass('error');
            }
        });

        if (isValid) {
            currentStep.removeClass('active');
            nextStep.addClass('active');
        } else {
            alert('Please fill in all required fields.');
        }
    });

    $('.wc-lead-prev').click(function () {
        var currentStep = $(this).closest('.wc-lead-step');
        var prevStep = currentStep.prev('.wc-lead-step');

        currentStep.removeClass('active');
        prevStep.addClass('active');
    });
});