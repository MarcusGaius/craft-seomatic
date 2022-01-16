/**
 * SEOmatic plugin for Craft CMS 3.x
 *
 * A turnkey SEO implementation for Craft CMS that is comprehensive, powerful,
 * and flexible
 *
 * @link      https://nystudio107.com
 * @copyright Copyright (c) 2017 nystudio107
 */

/**
 * @author    nystudio107
 * @package   SEOmatic
 * @since     3.0.0
 */
import VueEvents from 'vue-events';
import ContentSeoTable from '@/vue/ContentSeoTable.vue';

Vue.use(VueEvents);
// Create our vue instance
const vm = new Vue({
  el: "#cp-nav-content",
  components: {
    'content-seo-table': ContentSeoTable,
  },
  data: {},
  mounted() {
    this.$events.$on('refresh-table', eventData => this.onTableRefresh(eventData));
  },
  methods: {
    onTableRefresh(vuetable) {
      Vue.nextTick(() => vuetable.refresh());
    }
  },
});
