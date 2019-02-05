(function($) {

    /** global: Craft */
    /** global: Garnish */
    if (!Craft || !Craft.SEOMatePlugin || !Craft.SEOMatePlugin.previewAction) {
        return;
    }

    var SEOmatePreview = Garnish.Base.extend({

        $toggleBtn: null,
        preview: null,

        init: function () {
            // Create preview btn
            var $lpBtn = $('#header .livepreviewbtn');
            if (!$lpBtn.length) {
                return;
            }
            this.$toggleBtn = $lpBtn.clone();
            this.$toggleBtn.text(Craft.t('seomate', 'SEO Preview')).removeClass('livepreviewbtn').addClass('seopreviewbtn');
            this.$toggleBtn.on('click', $.proxy(this.onPreviewBtnClick, this));
            $lpBtn.after(this.$toggleBtn);
        },

        open: function () {

            if (!Craft.livePreview) {
                return;
            }

            if (this.preview) {
                this.preview.toggle();
                return;
            }

            // Get fields to display
            var fields = [].concat.apply([], Object.values(window.SEOMATE_FIELD_PROFILE || {})).map(function (handle) {
                if (handle === 'title') {
                    return '#title-field';
                }
                return '#fields-' + handle.split(':')[0] + '-field';
            }).join(',');

            this.preview = new Craft.LivePreview();
            this.preview.init($.extend(Craft.livePreview.settings, {
                fields: fields,
                previewAction: Craft.SEOMatePlugin.previewAction
            }));

            this.preview.on('enter', $.proxy(function () {
                this.preview.$editor.find('.btn:first-child').text(Craft.t('seomate', 'Close SEO Preview'));
            }, this));

            this.preview.toggle();
        },

        onPreviewBtnClick: function (e) {
            e.preventDefault();
            e.stopPropagation();
            this.open();
        }

    });

    Garnish.$doc.ready(function () {
        new SEOmatePreview();
    });

}(jQuery));
