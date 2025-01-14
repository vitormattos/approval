/* jshint esversion: 6 */

/**
 * Nextcloud - Approval
 *
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2021
 */

import Vue from 'vue'
import './bootstrap'
import AdminSettings from './components/AdminSettings'
import Tooltip from '@nextcloud/vue/dist/Directives/Tooltip'
Vue.directive('tooltip', Tooltip)

// eslint-disable-next-line
'use strict'

// eslint-disable-next-line
new Vue({
	el: '#approval_prefs',
	render: h => h(AdminSettings),
})
