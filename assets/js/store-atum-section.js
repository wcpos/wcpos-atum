(function () {
  function registerAtumSection() {
    if (!window.wcpos || !window.wcpos.storeEdit || typeof window.wcpos.storeEdit.registerSection !== 'function') {
      setTimeout(registerAtumSection, 40);
      return;
    }

    var config = window.wcposAtumStoreEdit || {};
    var locations = Array.isArray(config.locations) ? config.locations : [];
    var strings = config.strings || {};

    if (window.wcpos.storeEdit.getSections && window.wcpos.storeEdit.getSections().has('wcpos-atum-inventory')) {
      return;
    }

    var el = window.wp && window.wp.element ? window.wp.element.createElement : null;
    if (!el) {
      return;
    }

    var selectClass = 'wcpos:block wcpos:w-full wcpos:rounded-md wcpos:border wcpos:border-gray-300 wcpos:px-2.5 wcpos:py-1.5 wcpos:text-sm wcpos:shadow-xs wcpos:focus:outline-none wcpos:focus:ring-2 wcpos:focus:ring-wp-admin-theme-color wcpos:focus:border-wp-admin-theme-color';

    function AtumSection(props) {
      var locationValue = props.store.atum_inventory_location || '';
      var pricingValue = props.store.pricing_source || 'default';
      var skuValue = props.store.atum_sku_override || '';

      var hasLocation = locationValue && locationValue !== '0';

      return el('div', { className: 'wcpos:border-b wcpos:border-gray-200 wcpos:pb-6 wcpos:space-y-6' },

        // Section header.
        el('div', { className: 'wcpos:mb-4' },
          el('h3', { className: 'wcpos:text-base wcpos:font-semibold wcpos:text-gray-900 wcpos:m-0' },
            strings.sectionLabel || 'ATUM Inventory'
          )
        ),

        // Location dropdown.
        el('div', null,
          el('label', { className: 'wcpos:block wcpos:text-sm wcpos:font-medium wcpos:text-gray-700 wcpos:mb-1' },
            strings.locationTitle || 'Inventory location'
          ),
          el('p', { className: 'wcpos:text-xs wcpos:text-gray-500 wcpos:mb-2' },
            strings.locationDescription || ''
          ),
          locations.length > 0
            ? el('select', {
                className: selectClass,
                value: locationValue,
                onChange: function (e) { props.onChange('atum_inventory_location', e.target.value); }
              },
              el('option', { value: '' }, strings.locationDefault || 'No location'),
              locations.map(function (loc) {
                return el('option', { key: loc.value, value: loc.value }, loc.label);
              })
            )
            : el('p', { className: 'wcpos:text-sm wcpos:text-gray-500' }, strings.noLocations || 'No locations found.')
        ),

        // Pricing source (only shown when location is set).
        hasLocation ? el('div', null,
          el('label', { className: 'wcpos:block wcpos:text-sm wcpos:font-medium wcpos:text-gray-700 wcpos:mb-1' },
            strings.pricingTitle || 'Pricing source'
          ),
          el('p', { className: 'wcpos:text-xs wcpos:text-gray-500 wcpos:mb-2' },
            strings.pricingDescription || ''
          ),
          el('select', {
              className: selectClass,
              value: pricingValue,
              onChange: function (e) { props.onChange('pricing_source', e.target.value); }
            },
            el('option', { value: 'default' }, strings.pricingDefault || 'Default'),
            el('option', { value: 'wcpos_pro' }, strings.pricingPro || 'WCPOS Pro'),
            el('option', { value: 'atum' }, strings.pricingAtum || 'ATUM')
          )
        ) : null,

        // SKU override (only shown when location is set).
        hasLocation ? el('div', null,
          el('label', { className: 'wcpos:flex wcpos:items-center wcpos:gap-2 wcpos:text-sm wcpos:text-gray-700 wcpos:cursor-pointer' },
            el('input', {
              type: 'checkbox',
              checked: skuValue === '1',
              onChange: function (e) { props.onChange('atum_sku_override', e.target.checked ? '1' : ''); },
              className: 'wcpos:rounded wcpos:border-gray-300'
            }),
            strings.skuLabel || 'Use location-specific SKUs'
          )
        ) : null
      );
    }

    window.wcpos.storeEdit.registerSection('wcpos-atum-inventory', {
      component: AtumSection,
      label: strings.sectionLabel || 'ATUM Inventory',
      column: 'sidebar',
      priority: 34
    });
  }

  registerAtumSection();
})();
