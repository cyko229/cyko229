/* Mugiwara LMS : administration du parrainage (copie, confirmations, Médiathèque, simulation). */
(function ($) {
	'use strict';

	// Confirmation avant une action sensible.
	$(document).on('submit', 'form[data-confirm]', function (e) {
		if (!window.confirm($(this).data('confirm'))) { e.preventDefault(); }
	});

	// Copie du numéro Mobile Money.
	$(document).on('click', '[data-mla-copy]', function () {
		var btn = $(this);
		var text = String(btn.data('mlaCopy'));
		var done = function () {
			btn.addClass('is-copied').text('Copié');
			setTimeout(function () { btn.removeClass('is-copied').text('Copier'); }, 1500);
		};
		var fallback = function () {
			var t = $('<textarea>').val(text).css({ position: 'fixed', opacity: 0 }).appendTo('body');
			t[0].select();
			try { document.execCommand('copy'); done(); } catch (err) {}
			t.remove();
		};
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(done, fallback);
		} else {
			fallback();
		}
	});

	// Image de la pièce Berry depuis la Médiathèque.
	var frame;
	$(document).on('click', '[data-mla-media-pick]', function (e) {
		e.preventDefault();
		if (!window.wp || !wp.media) { return; }
		if (!frame) {
			frame = wp.media({ title: 'Image de la pièce Berry', button: { text: 'Utiliser cette image' }, library: { type: 'image' }, multiple: false });
			frame.on('select', function () {
				var img = frame.state().get('selection').first().toJSON();
				var url = (img.sizes && img.sizes.thumbnail ? img.sizes.thumbnail.url : img.url);
				$('[data-mla-media-input]').val(url);
				$('[data-mla-media-preview]').html($('<img>').attr({ src: url, alt: '' }));
				$('[data-mla-media-clear]').prop('hidden', false);
			});
		}
		frame.open();
	});
	$(document).on('click', '[data-mla-media-clear]', function () {
		$('[data-mla-media-input]').val('');
		$('[data-mla-media-preview]').html('<em>Pièce par défaut (après enregistrement)</em>');
		$(this).prop('hidden', true);
	});

	// Simulation : commission d'une vente avec les réglages saisis.
	var sim = $('[data-mla-sim]');
	if (sim.length) {
		var fmt = function (n, d) { return Number(n).toLocaleString('fr-FR', { maximumFractionDigits: d || 0 }); };
		var update = function () {
			var price = parseFloat($('[data-mla-sim-price]').val()) || 0;
			var rate = parseFloat($('[data-mla-sim-aff-rate]').val()) || 0;
			var value = parseFloat($('[data-mla-sim-berry-value]').val()) || 1;
			var min = parseFloat($('[data-mla-sim-berry-min]').val()) || 0;
			var fcfa = price * rate / 100;
			var berry = fcfa / value;
			$('[data-mla-sim-fcfa]').text(fmt(fcfa) + ' FCFA');
			$('[data-mla-sim-berry]').text(fmt(berry, 2) + ' Berry');
			$('[data-mla-sim-sales]').text(berry > 0 ? fmt(Math.ceil(min / berry)) + ' vente(s)' : '—');
		};
		$(document).on('input', '[data-mla-sim-price],[data-mla-sim-aff-rate],[data-mla-sim-berry-value],[data-mla-sim-berry-min]', update);
		update();
	}
})(jQuery);
