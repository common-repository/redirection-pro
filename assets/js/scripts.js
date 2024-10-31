document.addEventListener('DOMContentLoaded', function () {

    function removeElementFromTemplate(template, selector) {

        var newTemplate = document.createElement('template');

        newTemplate.innerHTML = template.innerHTML;

        var content = newTemplate.content;

        var elementsToRemove = content.querySelectorAll(selector);

        [].forEach.call(elementsToRemove , function(element){
            element.remove();
        });

        return newTemplate;
    }

    var countdownElement = document.getElementById('redirection-pro-timer-countdown');
    var timer = REDIRECTION_PRO_DATA.timer;
    var intervalId = NaN;

    if (countdownElement && REDIRECTION_PRO_DATA.href.length) {
        intervalId = setInterval(function () {
            timer--;
            countdownElement.textContent = timer;
            if (timer <= 0) {
                clearInterval(intervalId);
                window.location.href = REDIRECTION_PRO_DATA.href;
            }
        }, 1000);
    }

    if (window.tippy) {

        var links = document.querySelectorAll('[data-rp-preview]');

        [].forEach.call(links, function (link) {

            var preview = link.dataset['rpPreview'] || false;
            var templateId = 'rp-og-preview-template';
            var url = link.dataset['rpUrl'] || false;

            if (!url) {
                return;
            }

            url = url.replace(/\/$/, '').replace(/^https?:\/\//, '');

            if (preview !== 'true') {
                templateId = 'rp-preview-template';
            }

            var template = document.getElementById(templateId);

            var name = link.dataset['ogSiteName'] || false;
            var title = link.dataset['ogTitle'] || false;
            var description = link.dataset['ogDescription'] || false;
            var image = link.dataset['ogImage'] || false;


            if (title === false && name !== false) {
                title = name;
            }

            if (!title.length) {
                template = removeElementFromTemplate(template, '.rp-og-preview-title-wrap > h3');
            }

            if (image === false) {
                template = removeElementFromTemplate(template, '.rp-og-preview-image-wrap');
            }

            if (description === false) {
                template = removeElementFromTemplate(template, '.rp-og-preview-description');
            }

            var content = template.innerHTML
                .replace('%TITLE%', title)
                .replace('%FAVICON%', 'https://www.google.com/s2/favicons?domain=' + url)
                .replace('%DESCRIPTION%', description)
                .replace('%IMG%', image)
                .replace('%ALT%', title)
                .replace('%URL%', url)

            // Initialize Tippy.js with the options
            tippy(link, {
                maxWidth: 360,
                content: content,
                allowHTML: true,
                theme: REDIRECTION_PRO_DATA.theme,
                animation: REDIRECTION_PRO_DATA.animation,
                delay: REDIRECTION_PRO_DATA.delay,
                arrow: true
            });

        });

    }

});


