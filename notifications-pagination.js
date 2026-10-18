/* ============================================================
   AJAX pagination for the notification cards
   (Approved posts + Post warnings).
   Intercepts clicks on the pagination controls, fetches the
   requested page and swaps the card body in place.
   ============================================================ */
(function () {
    'use strict';

    function pageFromHref(href) {
        var m = /[?&]page=(\d+)/.exec(href || '');
        return m ? m[1] : '1';
    }

    function wireCard(cardSelector, bodySelector, paginationSelector) {
        var card = document.querySelector(cardSelector);
        if (!card) return;

        var body = card.querySelector(bodySelector);
        if (!body) return;

        card.addEventListener('click', function (e) {
            var link = e.target.closest(paginationSelector + ' a');
            if (!link || link.classList.contains('is-disabled')) return;

            e.preventDefault();

            var page = pageFromHref(link.getAttribute('href'));
            var url = new URL(window.location.href);
            url.searchParams.set('page', page);

            var currentBody = card.querySelector(bodySelector);
            if (!currentBody) return;

            currentBody.style.opacity = '0.45';
            currentBody.style.pointerEvents = 'none';

            fetch(url.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (res) { return res.text(); })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var newBody = doc.querySelector(bodySelector);
                    if (!newBody) throw new Error('paginated body not found');

                    var target = card.querySelector(bodySelector);
                    target.innerHTML = newBody.innerHTML;
                    target.style.opacity = '';
                    target.style.pointerEvents = '';

                    history.pushState({ page: page }, '', url.toString());

                    var top = card.getBoundingClientRect().top + window.pageYOffset - 24;
                    window.scrollTo({ top: top, behavior: 'smooth' });
                })
                .catch(function () {
                    window.location.href = url.toString();
                });
        });
    }

    wireCard('.apn-card', '.apn-body', '.apn-pagination');
    wireCard('.wrn-card', '.wrn-body', '.wrn-pagination');
    wireCard('.hrn-card', '.hrn-body', '.hrn-pagination');

    window.addEventListener('popstate', function () {
        window.location.reload();
    });
})();
