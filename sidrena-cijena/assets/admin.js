(function ($) {
    'use strict';

    function runBatches(opts) {
        var $btn = $(opts.button), $bar = $(opts.progress), $log = $(opts.log);
        var state = { last_id: 0, written: 0, skipped: 0 };
        $btn.prop('disabled', true);
        $bar.prop('hidden', false).find('span').css('width', '0%');
        $log.text('Pokrećem…');

        function step(extra) {
            var data = $.extend({ action: opts.action, nonce: scAdmin.nonce }, extra || {});
            $.post(scAdmin.ajax, data).done(function (res) {
                if (!res || !res.success) {
                    $log.html('<span style="color:#b32d2e">Greška: ' + ((res && res.data && res.data.message) || 'nepoznata') + '</span>');
                    $btn.prop('disabled', false);
                    return;
                }
                var d = res.data;
                var pct = opts.progressFn(d, state);
                $bar.find('span').css('width', Math.min(100, pct) + '%');
                $log.text(opts.logFn(d, state));
                if (d.done) {
                    $btn.prop('disabled', false);
                    $log.html(opts.doneFn(d, state));
                } else {
                    step(opts.nextFn(d));
                }
            }).fail(function (xhr) {
                $log.html('<span style="color:#b32d2e">Greška servera (' + xhr.status + '). Pokušaj ponovno; obrada nastavlja gdje je stala.</span>');
                $btn.prop('disabled', false);
            });
        }
        step(opts.first || {});
    }

    $('#sc-snapshot-btn').on('click', function (e) {
        e.preventDefault();
        var overwrite = $('#sc-overwrite').is(':checked') ? 1 : 0;
        if (overwrite && !confirm('Prepisat će se SVE postojeće sidrene cijene, uključujući ručne unose. Nastaviti?')) {
            return;
        }
        var totals = { written: 0, skipped: 0 };
        runBatches({
            button: this, progress: '#sc-snapshot-progress', log: '#sc-snapshot-log',
            action: 'sc_snapshot_batch',
            first: { last_id: 0, overwrite: overwrite },
            nextFn: function (d) { return { last_id: d.last_id, overwrite: overwrite }; },
            progressFn: function (d) { totals.written += d.written; totals.skipped += d.skipped; return d.done ? 100 : 50; },
            logFn: function (d) { return 'Obrađeno do ID ' + d.last_id + ' · zabilježeno: ' + totals.written + ' · preskočeno: ' + totals.skipped; },
            doneFn: function (d) { var st = d.stats ? ' Sa sidrenom cijenom: ' + d.stats.with + ', bez: ' + d.stats.without + '.' : ''; return '<span style="color:#00a32a">Gotovo.</span> Zabilježeno: ' + totals.written + ', preskočeno (već postoji): ' + totals.skipped + '.' + st + ' <a href="">Osvježi stranicu</a>'; }
        });
    });

    $('#sc-export-btn, #sc-export-resume-btn').on('click', function (e) {
        e.preventDefault();
        var resume = this.id === 'sc-export-resume-btn';
        if (!resume && $('#sc-export-resume-btn').length && !confirm('Nedovršeno generiranje bit će odbačeno i počinje se ispočetka. Nastaviti?')) {
            return;
        }
        $('#sc-export-btn, #sc-export-resume-btn').prop('disabled', true);
        runBatches({
            button: this, progress: '#sc-export-progress', log: '#sc-export-log',
            action: 'sc_export_batch',
            first: resume ? {} : { restart: 1 },
            nextFn: function () { return {}; },
            progressFn: function (d) { return d.total ? Math.round(100 * d.offset / d.total) : 100; },
            logFn: function (d) { return 'Obrađeno ' + d.offset + ' / ' + d.total + ' proizvoda · redaka: ' + d.rows; },
            doneFn: function (d) { return '<span style="color:#00a32a">Cjenik generiran.</span> ' + d.rows + ' redaka' + (d.missing ? ', <span style="color:#b32d2e">' + d.missing + ' bez sidrene cijene</span>' : '') + '. <a href="">Osvježi stranicu</a>'; }
        });
    });
})(jQuery);
