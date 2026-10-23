define(['chartJs'], function (Chart) {
    'use strict';

    return function (config, element) {
        new Chart(element, config);
    };
});
