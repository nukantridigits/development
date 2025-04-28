Ext.define('kDesktop.transportation3', {
	extend: 'Ext.tab.Panel',
	moduleId: 'transportation3',
	constructor: function(config) {
		config = config || {};
		this.app = config.app;
		this.newWNDID = 0;

		const LsHelper = helpers.localStorage
		const dealFromTicketAlias = LsHelper.alias.DEAL_FROM_TICKET
		const newDealData = LsHelper.getStateValue(dealFromTicketAlias, 'model')
		LsHelper.removeStateAlias(dealFromTicketAlias)

		Ext.applyIf(config, {
			border: false,
			closable: false,
			title: '',
			layout: 'fit',
			activeItem: 0,
			items: [
				Ext.create('kDesktop.transportation3.transportations', {
					ownerModule: this,
					parent: this,
					data: config.data,
					newDealData,
					clientConfig: config?.clientConfig ?? {},
					itemId: 'dealsGridTab'
				})
			]
		})

		kDesktop.transportation3.superclass.constructor.call(this, config);
	},
	getTabItem: function (id) {
		if (!id) return null

		const items = this.items?.items ?? []
		const TypesHelper = helpers.types

		if (!TypesHelper.isArrayWithLength(items)) return null

		if (id === 'new') {
			for (const item of items) {
				if (item?.mode !== 'new') continue
				return item
			}
		}

		const numericId = parseInt(id)

		for (const item of items) {
			const itemTid = item?.tid ? parseInt(item.tid) : null
			if (Number.isInteger(numericId) && itemTid === numericId) {
				return item
			}
		}

		return null
	},
	showMask: function(msg) {
		this.body.mask(msg + '...', 'x-mask-loading');
	},
	hideMask: function() {
		this.body.unmask();
	}
});

Ext.define('kDesktop.transportation3.transportationsFilterPanel', {
	extend: 'Ext.panel.Panel',
	constructor: function(config) {
		this.addEvents('onDealOpen')
		config = config || {};

		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.priv = this.parent.priv;
		this.clientConfig = config.clientConfig || {}
		this.showNbKzCurrencyRatesBlock = this.clientConfig?.showNbKzCurrencyRatesBlock ?? false
		this.title = 'Фильтр';
		this.closable = false;
		this.selectedRowData = null

		this.permissions = common.currentUser.getPermissions()
		const RolesHelper = helpers.roles

		const width = Math.round(kDesktop.app.getWinWidth() / 4);
		this.taskPanel = Ext.create('transpPanels.tasks.index', {
			itemId: 'transpTasksFrame',
			region: 'east',
			width: (width > 300) ? width : 300,
			collapsible: true,
			animCollapse: false,
			split: true,
			ownerModule: this.ownerModule,
			parent: this
		})

		this.mon(this.taskPanel, 'onDealOpen', (data) => this.fireEvent('onDealOpen', data), this)

		let transportTypeList = this.clientConfig?.transportTypeList ?? []
		if (helpers.types.isObjectAndHasProps(transportTypeList)) {
			transportTypeList = helpers.data.convertObjectToStoreData(transportTypeList)
		}

		const canSeeClients = !RolesHelper.isMainMenuTabHidden(this.permissions, RolesHelper.TAB_CLIENTS_NAME)
		const canSeeCarriers = !RolesHelper.isMainMenuTabHidden(this.permissions, RolesHelper.TAB_FERRYMANS_NAME)

		if (!canSeeClients) {
			this.clientFilterStore = Ext.create('Ext.data.Store', {
				fields: ['id', 'name'],
				data: []
			})
		} else {
			this.clientFilterStore = Ext.create('Ext.data.Store', {
				pageSize: 40,
				idProperty: 'id',
				autoLoad: false,
				fields: ['id','name'],
				proxy: {
					actionMethods: 'POST',
					type: 'ajax',
					url: this.ownerModule.app.connectUrl,
					extraParams: {
						module: 'statistics',
						method: 'getClientList'
					},
					reader: {
						type: 'json',
						root: 'items',
						totalProperty: 'totalCount'
					}
				}
			})
		}

		if (!canSeeCarriers) {
			this.carrierFilterStore = Ext.create('Ext.data.Store', {
				fields: ['id', 'name'],
				data: []
			})
		} else {
			this.carrierFilterStore = Ext.create('Ext.data.Store', {
				pageSize: 40,
				root: 'items',
				idProperty: 'id',
				remoteSort: true,
				autoLoad: true,
				fields: [
					'id',
					'name'
				],
				proxy: {
					actionMethods: 'POST',
					type: 'ajax',
					url: this.ownerModule.app.connectUrl,
					extraParams: {
						module: 'statistics',
						method: 'getCarrierList'
					},
					reader: {
						type: 'json',
						root: 'items',
						totalProperty: 'totalCount'
					}
				}
			})
		}

		const carrierFilterCombobox = {
			xtype: 'combobox',
			width: 400,
			labelWidth: 145,
			labelSeparator: '',
			minChars: 3,
			name: 'ferryman',
			queryMode: 'remote',
			fieldLabel: 'Подрядчик',
			displayField: 'name',
			valueField: 'id',
			store: this.carrierFilterStore,
			disabled: !canSeeCarriers
		}

		const clientFilterCombobox = Ext.create('form.comboboxWithSearch', {
			ref: 'clientFilterCmb',
			name: 'client',
			store: this.clientFilterStore,
			minChars: 3,
			fieldLabel: 'Клиент',
			alias: 'client_filter',
			labelSeparator: '',
			width: 400,
			labelWidth: 145,
			disabled: !canSeeClients
		})

		this.mainForm = Ext.create('Ext.form.Panel', {
			region: 'center',
			border: false,
			frame: true,
			layoutOnChange: true,
			deferredRender: false,
			items: [
				{
					xtype : 'container',
					layout: {
						type: 'hbox'
					},
					items: [
						{xtype: 'displayfield', width: 150, value: 'Номер'},
						{
							xtype: 'textfield',
							name: 'id',
							width: 250
						},
						{xtype: 'displayfield', width: 40, value: ''},
						{xtype: 'displayfield', width: 120, value: 'Логист'},
						{
							xtype: 'combobox',
							name: 'logist',
							width: 250,
							queryMode: 'local',
							ref: 'searchLogistCmb',
							displayField: 'value',
							valueField: 'key',
							store: Ext.create('Ext.data.JsonStore', {fields: ['key', 'value'], idProperty: 'key'})
						},
						{xtype: 'displayfield', width: 40, value: ''},
						{xtype: 'displayfield', width: 50, value: '<span style="color:#177ECB; font-weight:bold">cbr ru</span>', cls: 'rate-cell'},
						{xtype: 'displayfield', width: 70, ref: 'ratesCbrPrevDayFld', value: '&nbsp;', cls: 'align-right rate-cell'},
						{xtype: 'displayfield', width: 70, ref: 'ratesCbrLastDayFld', value: '&nbsp;', cls: 'align-right rate-cell'},
						{xtype: 'displayfield', width: 40, value: ''},
						{
							xtype: 'displayfield',
							width: 50,
							value: '<span style="color:#177ECB; font-weight:bold">nb kz</span>',
							cls: 'rate-cell',
							hidden: !this.showNbKzCurrencyRatesBlock
						},
						{
							xtype: 'displayfield',
							width: 70,
							ref: 'ratesNbkzPrevDayFld',
							value: '&nbsp;',
							hidden: !this.showNbKzCurrencyRatesBlock,
							cls: 'align-right rate-cell'
						},
						{
							xtype: 'displayfield',
							width: 70,
							ref: 'ratesNbkzLastDayFld',
							value: '&nbsp;',
							hidden: !this.showNbKzCurrencyRatesBlock,
							cls: 'align-right rate-cell'
						}
					]
				},
				{
					xtype : 'container',
					layout: {
						type: 'hbox'
					},
					items: [
						{xtype: 'displayfield', width: 140, value: 'Дата создания'},
						{xtype: 'displayfield', width: 10, value: 'с'},
						{
							xtype: 'datefield',
							name: 'createdate1',
							width: 113,
							allowBlank: true,
							format: 'd.m.Y',
							editable: true,
							startDay: 1
						},
						{xtype: 'displayfield', width: 24, value: '&nbsp;по'},
						{
							xtype: 'datefield',
							name: 'createdate2',
							width: 113,
							allowBlank: true,
							format: 'd.m.Y',
							editable: true,
							startDay: 1
						},
						{xtype: 'displayfield', width: 40, value: ''},
						{xtype: 'displayfield', width: 120, value: 'Менеджер'},
						{
							xtype: 'combobox',
							name: 'manager',
							width: 250,
							queryMode: 'local',
							ref: 'searchManagerCmb',
							displayField: 'value',
							valueField: 'key',
							store: Ext.create('Ext.data.JsonStore', {fields: ['key', 'value'], idProperty: 'key'})
						},
						{xtype: 'displayfield', width: 40, value: ''},
						{
							xtype: 'displayfield',
							width: 50,
							value: 'USD',
							style: 'border-bottom: 1px solid #bec8d4;',
							cls: 'rate-cell'
						},
						{
							xtype: 'displayfield',
							width: 70,
							ref: 'ratesCbrPrevUsdFld',
							value: '&nbsp;',
							style: 'border-bottom: 1px solid #bec8d4;',
							cls: 'align-right rate-cell'
						},
						{
							xtype: 'displayfield',
							width: 70,
							ref: 'ratesCbrLastUsdFld',
							value: '&nbsp;',
							style: 'border-bottom: 1px solid #bec8d4;',
							cls: 'align-right rate-cell'
						}, {xtype: 'displayfield', width: 40, value: ''},
						{
							xtype: 'displayfield',
							width: 50,
							value: 'USD',
							style: 'border-bottom: 1px solid #bec8d4;',
							hidden: !this.showNbKzCurrencyRatesBlock,
							cls: 'rate-cell'
						},
						{
							xtype: 'displayfield',
							width: 70,
							ref: 'ratesNbkzPrevUsdFld',
							value: '&nbsp;',
							style: 'border-bottom: 1px solid #bec8d4;',
							hidden: !this.showNbKzCurrencyRatesBlock,
							cls: 'align-right rate-cell'
						},
						{
							xtype: 'displayfield',
							width: 70,
							ref: 'ratesNbkzLastUsdFld',
							value: '&nbsp;',
							style: 'border-bottom: 1px solid #bec8d4;',
							hidden: !this.showNbKzCurrencyRatesBlock,
							cls: 'align-right rate-cell'
						}
					]
				},
				{
					xtype : 'container',
					layout: {
						type: 'hbox'
					},
					items: [
						{xtype: 'displayfield', width: 140, value: 'Дата загрузки'},
						{xtype: 'displayfield', width: 10, value: 'с'},
						{
							xtype: 'datefield',
							name: 'loaddate1',
							width: 113,
							allowBlank: true,
							format: 'd.m.Y',
							editable: true,
							startDay: 1
						},
						{xtype: 'displayfield', width: 24, value: '&nbsp;по'},
						{
							xtype: 'datefield',
							name: 'loaddate2',
							width: 113,
							allowBlank: true,
							format: 'd.m.Y',
							editable: true,
							startDay: 1
						},
						{xtype: 'displayfield', width: 40, value: ''},
						{xtype: 'displayfield', width: 120, value: 'Международная'},
						{
							xtype: 'combobox',
							name: 'country',
							ref: 'searchCountryCmb',
							width: 250,
							queryMode: 'local',
							displayField: 'name',
							valueField: 'id',
							editable: false,
							value: 0,
							store: Ext.create('Ext.data.ArrayStore', {
								fields: [
									'id',
									'name'
								],
								data: [
									['0', 'Все'],
									['1', 'РФ'],
									['2', 'Международная']
								]
							})
						},
						{xtype: 'displayfield', width: 40, value: ''},
						{xtype: 'displayfield', width: 50, value: 'EUR', style: 'border-bottom: 1px solid #bec8d4;', cls: 'rate-cell'},
						{xtype: 'displayfield', width: 70, ref: 'ratesCbrPrevEurFld', value: '&nbsp;', style: 'border-bottom: 1px solid #bec8d4;', cls: 'align-right rate-cell'},
						{xtype: 'displayfield', width: 70, ref: 'ratesCbrLastEurFld', value: '&nbsp;', style: 'border-bottom: 1px solid #bec8d4;', cls: 'align-right rate-cell'},
						{xtype: 'displayfield', width: 40, value: ''},
						{
							xtype: 'displayfield',
							width: 50,
							value: 'EUR',
							style: 'border-bottom: 1px solid #bec8d4;',
							hidden: !this.showNbKzCurrencyRatesBlock,
							cls: 'rate-cell'
						},
						{
							xtype: 'displayfield',
							width: 70,
							ref: 'ratesNbkzPrevEurFld',
							value: '&nbsp;',
							style: 'border-bottom: 1px solid #bec8d4;',
							hidden: !this.showNbKzCurrencyRatesBlock,
							cls: 'align-right rate-cell'
						},
						{
							xtype: 'displayfield',
							width: 70,
							ref: 'ratesNbkzLastEurFld',
							value: '&nbsp;',
							style: 'border-bottom: 1px solid #bec8d4;',
							hidden: !this.showNbKzCurrencyRatesBlock,
							cls: 'align-right rate-cell'
						}
					]
				},
				{
					xtype : 'container',
					layout: {
						type: 'hbox'
					},
					items: [
						{xtype: 'displayfield', width: 140, value: 'Дата выгрузки'},
						{xtype: 'displayfield', width: 10, value: 'с'},
						{
							xtype: 'datefield',
							name: 'offloaddate1',
							width: 113,
							allowBlank: true,
							format: 'd.m.Y',
							editable: true,
							startDay: 1
						},
						{xtype: 'displayfield', width: 24, value: '&nbsp;по'},
						{
							xtype: 'datefield',
							name: 'offloaddate2',
							width: 113,
							allowBlank: true,
							format: 'd.m.Y',
							editable: true,
							startDay: 1
						},
						{xtype: 'displayfield', width: 40, value: ''},
						{xtype: 'displayfield', width: 120, value: 'Тип услуг'},
						{
							xtype: 'combobox',
							name: 'typets',
							width: 250,
							queryMode: 'local',
							displayField: 'value',
							valueField: 'key',
							editable: true,
							listeners: {
								beforequery: function(queryEvent) {
									const combo = queryEvent.combo
									const store = combo.getStore()
									const queryString = queryEvent.query.toLowerCase()

									store.filterBy(function(record) {
										const value = record.get(combo.displayField).toLowerCase()
										return value.indexOf(queryString) !== -1
									})

									queryEvent.cancel = true
									combo.expand()
								}
							},
							store: Ext.create('Ext.data.JsonStore', {
								fields: ['key', 'value'],
								idProperty: 'key',
								data: transportTypeList
							})
						},
						{xtype: 'displayfield', width: 40, value: ''},
						{xtype: 'displayfield', width: 50, value: 'KZT', style: 'border-bottom: 1px solid #bec8d4;', cls: 'rate-cell'},
						{xtype: 'displayfield', width: 70, ref: 'ratesCbrPrevKztFld', value: '&nbsp;', style: 'border-bottom: 1px solid #bec8d4;', cls: 'align-right rate-cell'},
						{xtype: 'displayfield', width: 70, ref: 'ratesCbrLastKztFld', value: '&nbsp;', style: 'border-bottom: 1px solid #bec8d4;', cls: 'align-right rate-cell'},
						{xtype: 'displayfield', width: 40, value: ''},
						{
							xtype: 'displayfield',
							width: 50,
							value: 'RUR',
							style: 'border-bottom: 1px solid #bec8d4;',
							hidden: !this.showNbKzCurrencyRatesBlock,
							cls: 'rate-cell'
						},
						{
							xtype: 'displayfield',
							width: 70,
							ref: 'ratesNbkzPrevRurFld',
							value: '&nbsp;',
							style: 'border-bottom: 1px solid #bec8d4;',
							hidden: !this.showNbKzCurrencyRatesBlock,
							cls: 'align-right rate-cell'
						},
						{
							xtype: 'displayfield',
							width: 70,
							width: 70,
							ref: 'ratesNbkzLastRurFld',
							value: '&nbsp;',
							style: 'border-bottom: 1px solid #bec8d4;',
							hidden: !this.showNbKzCurrencyRatesBlock,
							cls: 'align-right rate-cell'
						}
					]
				},
				{
					xtype : 'container',
					layout: {
						type: 'hbox'
					},
					items: [
						clientFilterCombobox,
						{xtype: 'displayfield', width: 40, value: ''},
						{xtype: 'displayfield', width: 120, value: 'Водитель'},
						{ // TODO remake
							xtype: 'combobox',
							width: 250,
							name: 'ferryfiodriver',
							queryMode: 'remote',
							pageSize: 40,
							displayField: 'name',
							valueField: 'name',
							store: Ext.create('Ext.data.Store', {
								pageSize: 40,
								root: 'items',
								idProperty: 'id',
								remoteSort: true,
								autoLoad: true,
								fields: [
									'id',
									'name'
								],
								proxy: {
									actionMethods: 'POST',
									type: 'ajax',
									url: this.ownerModule.app.connectUrl,
									extraParams: {
										module: this.ownerModule.moduleId,
										method: 'ferryFioDriverList'
									},
									reader: {
										type: 'json',
										root: 'items',
										totalProperty: 'totalCount'
									}
								}
							})
						},
						{xtype: 'displayfield', width: 40, value: ''},
						{xtype: 'displayfield', width: 50, value: 'CNY', style: 'border-bottom: 1px solid #bec8d4;', cls: 'rate-cell'},
						{xtype: 'displayfield', width: 70, ref: 'ratesCbrPrevCnyFld', value: '&nbsp;', style: 'border-bottom: 1px solid #bec8d4;', cls: 'align-right rate-cell'},
						{xtype: 'displayfield', width: 70, ref: 'ratesCbrLastCnyFld', value: '&nbsp;', style: 'border-bottom: 1px solid #bec8d4;', cls: 'align-right rate-cell'},
						{xtype: 'displayfield', width: 40, value: ''},
						{
							xtype: 'displayfield',
							width: 50,
							value: 'CNY',
							style: 'border-bottom: 1px solid #bec8d4;',
							hidden: !this.showNbKzCurrencyRatesBlock,
							cls: 'rate-cell'
						},
						{
							xtype: 'displayfield',
							width: 70,
							ref: 'ratesNbkzPrevCnyFld',
							value: '&nbsp;',
							style: 'border-bottom: 1px solid #bec8d4;',
							hidden: !this.showNbKzCurrencyRatesBlock,
							cls: 'align-right rate-cell'
						},
						{
							xtype: 'displayfield',
							width: 70,
							ref: 'ratesNbkzLastCnyFld',
							value: '&nbsp;',
							style: 'border-bottom: 1px solid #bec8d4;',
							hidden: !this.showNbKzCurrencyRatesBlock,
							cls: 'align-right rate-cell'
						}
					]
				},
				{
					xtype : 'container',
					layout: {
						type: 'hbox'
					},
					items: [
						carrierFilterCombobox,
						{xtype: 'displayfield', width: 40, value: ''},
						{xtype: 'displayfield', width: 120, value: 'Номер авто'},
						{
							xtype: 'combobox',
							width: 250,
							name: 'ferrycarnumber',
							queryMode: 'remote',
							pageSize: 40,
							displayField: 'name',
							valueField: 'name',
							store: Ext.create('Ext.data.Store', {
								pageSize: 40,
								root: 'items',
								idProperty: 'id',
								remoteSort: true,
								autoLoad: true,
								fields: [
									'id',
									'name'
								],
								proxy: {
									actionMethods: 'POST',
									type: 'ajax',
									url: this.ownerModule.app.connectUrl,
									extraParams: {
										module: this.ownerModule.moduleId,
										method: 'ferryCarNumberList'
									},
									reader: {
										type: 'json',
										root: 'items',
										totalProperty: 'totalCount'
									}
								}
							})
						},
						{xtype: 'displayfield', width: 40, value: ''},
						{xtype: 'displayfield', width: 50, value: 'UZS', cls: 'rate-cell'},
						{xtype: 'displayfield', width: 70, ref: 'ratesCbrPrevUzsFld', value: '&nbsp;', cls: 'align-right rate-cell'},
						{xtype: 'displayfield', width: 70, ref: 'ratesCbrLastUzsFld', value: '&nbsp;', cls: 'align-right rate-cell'},
						{xtype: 'displayfield', width: 40, value: ''},
						{
							xtype: 'displayfield',
							width: 50,
							value: 'UZS',
							hidden: !this.showNbKzCurrencyRatesBlock,
							cls: 'rate-cell'
						},
						{
							xtype: 'displayfield',
							width: 70,
							ref: 'ratesNbkzPrevUzsFld',
							value: '&nbsp;',
							hidden: !this.showNbKzCurrencyRatesBlock,
							cls: 'align-right rate-cell'
						},
						{
							xtype: 'displayfield',
							width: 70,
							ref: 'ratesNbkzLastUzsFld',
							value: '&nbsp;',
							hidden: !this.showNbKzCurrencyRatesBlock,
							cls: 'align-right rate-cell'
						}
					]
				},
				{
					xtype : 'container',
					layout: {
						type: 'hbox'
					},
					items: [
						{xtype: 'displayfield', width: 150, value: 'Маршрут (откуда/куда)'},
						{
							xtype: 'textfield',
							name: 'fromplace',
							width: 125
						},
						{
							xtype: 'textfield',
							name: 'toplace',
							width: 125
						},
						{xtype: 'displayfield', width: 40, value: ''},
						{xtype: 'displayfield', width: 120, value: 'Бухгалтер'},
						{
							xtype: 'combobox',
							width: 250,
							name: 'accountant',
							ref: 'searchAccountantCmb',
							queryMode: 'local',
							displayField: 'value',
							valueField: 'key',
							store: Ext.create('Ext.data.JsonStore', {fields: ['key', 'value'], idProperty: 'key'})
						}
					]
				},
				{
					xtype : 'container',
					layout: {
						type: 'hbox'
					},
					items: [
						{xtype: 'displayfield', width: 150, value: 'Направление'},
						{
							xtype: 'combobox',
							name: 'region',
							ref: 'searchRegionCmb',
							width: 250,
							queryMode: 'local',
							displayField: 'value',
							valueField: 'key',
							editable: false,
							store: Ext.create('Ext.data.JsonStore', {fields: ['key', 'value'], idProperty: 'key'})
						},
						{xtype: 'displayfield', width: 40, value: ''},
						{xtype: 'displayfield', width: 120, value: 'Номер счета'},
						{
							xtype: 'textfield',
							name: 'clientinvoice',
							width: 250
						},
						{xtype: 'displayfield', width: 10, value: ''},
						{
							xtype: 'combobox',
							name: 'invoiceStatus',
							fieldLabel: '',
							store: {
								fields: ['value', 'text'],
								data: [
									{value: 'all', text: 'Все'},
									{value: 'yes', text: 'Есть'},
									{value: 'no', text: 'Нет'}
								]
							},
							queryMode: 'local',
							displayField: 'text',
							valueField: 'value',
							width: 60,
							editable: false,
							value: 'all',
							margin: '0 0 0 -5'
						}
					]
				},
				{
					xtype : 'container',
					layout: {
						type: 'hbox'
					},
					items: [
						{xtype: 'displayfield', width: 150, value: 'Характер груза'},
						{
							xtype: 'textfield',
							name: 'cargo',
							width: 250,
						},
						{xtype: 'displayfield', width: 40, value: ''},
						{xtype: 'displayfield', width: 120, value: 'Номер акта'},
						{
							xtype: 'textfield',
							name: 'clientInvoiceActNumber',
							width: 250
						},
						{xtype: 'displayfield', width: 10, value: ''},
						{
							xtype: 'combobox',
							name: 'invoiceActStatus',
							fieldLabel: '',
							store: {
								fields: ['value', 'text'],
								data: [
									{value: 'all', text: 'Все'},
									{value: 'yes', text: 'Есть'},
									{value: 'no', text: 'Нет'}
								]
							},
							queryMode: 'local',
							displayField: 'text',
							valueField: 'value',
							width: 60,
							editable: false,
							value: 'all',
							margin: '0 0 0 -5'
						}
					]
				},
				{
					xtype: 'container',
					layout: {
						type: 'hbox',
						align: 'middle',
						pack: 'start'
					},
					width: '100%',
					items: [
						{
							xtype: 'button',
							text: 'Загрузить',
							scope: this,
							width: 80,
							handler: function () {
								/*this.parent.gridPanel.store.proxy.extraParams.filtr = Ext.encode(this.ownerModule.app.getFormValues(this.mainForm));
								this.parent.gridPanel.store.load();*/
							}
						},
						{xtype: 'displayfield', width: 20, value: ''},
						{
							xtype: 'button',
							text: 'D/C',
							width: 40,
							scope: this,
							handler: function () {
								const formValues = this.ownerModule.app.getFormValues(this.mainForm);
								const filter = Ext.encode(formValues);
								Ext.Msg.wait('Загрузка данных...', 'Пожалуйста, подождите')
								Ext.Ajax.request({
									url: 'index.php',
									method: 'POST',
									params: {
										module: 'transportation3',
										method: 'getDebitCredit',
										filter: filter
									},
									success: function (response) {
										Ext.Msg.hide()
										try {
											const res = Ext.decode(response.responseText);
											if (res && res.data) {
												Ext.create('kDesktop.dcModal', {
													data: res.data,
													listeners: {
														dcBalanceItemClick: (ids) => {
															const field = this.mainForm.getForm().findField('id')
															if (!field)
																return false

															field.setValue(ids)

															/*this.parent.gridPanel.store.proxy.extraParams.filtr = Ext.encode(this.ownerModule.app.getFormValues(this.mainForm))
															this.parent.gridPanel.store.load()		*/										}
													}
												}).show();
											} else {
												throw new Error('Response does not contain expected data');
											}
										} catch (error) {
											console.error('Error processing response:', error);
											Ext.Msg.alert('Error', 'An error occurred while processing the response: ' + error.message);
										}
									},
									failure: function (response) {
										Ext.Msg.hide()
										console.error('AJAX request failed:', response);
										Ext.Msg.alert('Error', 'Server error: ' + response.statusText);
									},
									scope: this
								});
							}
						},
						{xtype: 'displayfield', width: 20, value: ''},
						{
							xtype: 'button',
							text: 'Копировать маршрут',
							width: 140,
							scope: this,
							handler: () => {
								const direction = this.selectedRowData?.direction ?? null
								if (helpers.types.isNull(direction)) return false
								this.ownerModule.app.copyToClipboard(direction)
							}
						},
						{
							xtype: 'component',
							flex: 1
						},
						{
							xtype: 'checkbox',
							name: 'viewdeleted',
							width: 20,
							height: 21,
							margin: '0 -5 0 0'
						},
						{
							xtype: 'displayfield',
							value: 'Удаленные',
							width: 80,
							height: 21,
							margin: '0 0 0 0'
						}
					]
				}
			]
		});

		Ext.applyIf(config, {
			layout: 'border',
			items: [
				this.mainForm,
				this.taskPanel
			]
		});

		kDesktop.transportation3.transportationsFilterPanel.superclass.constructor.call(this, config);

 		this.ownerModule.app.createReference(this.mainForm);

		this.on('afterrender', function() {
			if (this.data.userList) {
				this.mainForm.searchManagerCmb.store.loadData(this.data.userList);
				this.mainForm.searchManagerCmb.select(0);

				this.mainForm.searchLogistCmb.store.loadData(this.data.userList);
				this.mainForm.searchLogistCmb.select(0);

				this.mainForm.searchAccountantCmb.store.loadData(this.data.userList);
				this.mainForm.searchAccountantCmb.select(0);
			}

			if (helpers.types.isArrayWithLength(this.data?.regionDict)) {
				this.mainForm.searchRegionCmb.store.loadData(this.data.regionDict)
			}

			if (this.data.rates) {
				if (this.data.rates.cbr) {
					if (this.data.rates.cbr.prev) {
						this.mainForm.ratesCbrPrevDayFld.setValue('<span style="color:#177ECB; font-weight:bold">' + this._r(this.data.rates.cbr.prev.day) + '</span>');
						this.mainForm.ratesCbrPrevUsdFld.setValue(this._r(this.data.rates.cbr.prev.USD));
						this.mainForm.ratesCbrPrevEurFld.setValue(this._r(this.data.rates.cbr.prev.EUR));
						this.mainForm.ratesCbrPrevKztFld.setValue(this._r(this.data.rates.cbr.prev.KZT));
						this.mainForm.ratesCbrPrevCnyFld.setValue(this._r(this.data.rates.cbr.prev.CNY));
						this.mainForm.ratesCbrPrevUzsFld.setValue(this._r(this.data.rates.cbr.prev.UZS));
					}
					if (this.data.rates.cbr.last) {
						this.mainForm.ratesCbrLastDayFld.setValue('<span style="color:#177ECB; font-weight:bold">' + this._r(this.data.rates.cbr.last.day) + '</span>');
						this.mainForm.ratesCbrLastUsdFld.setValue(this._r(this.data.rates.cbr.last.USD));
						this.mainForm.ratesCbrLastEurFld.setValue(this._r(this.data.rates.cbr.last.EUR));
						this.mainForm.ratesCbrLastKztFld.setValue(this._r(this.data.rates.cbr.last.KZT));
						this.mainForm.ratesCbrLastCnyFld.setValue(this._r(this.data.rates.cbr.last.CNY));
						this.mainForm.ratesCbrLastUzsFld.setValue(this._r(this.data.rates.cbr.last.UZS));
					}
				}

				if (this.showNbKzCurrencyRatesBlock && this.data.rates.nbkz) {
					if (this.data.rates.nbkz.prev) {
						this.mainForm.ratesNbkzPrevDayFld.setValue('<span style="color:#177ECB; font-weight:bold">' + this._r(this.data.rates.nbkz.prev.day) + '</span>');
						this.mainForm.ratesNbkzPrevUsdFld.setValue(this._r(this.data.rates.nbkz.prev.USD));
						this.mainForm.ratesNbkzPrevEurFld.setValue(this._r(this.data.rates.nbkz.prev.EUR));
						this.mainForm.ratesNbkzPrevRurFld.setValue(this._r(this.data.rates.nbkz.prev.RUR));
						this.mainForm.ratesNbkzPrevCnyFld.setValue(this._r(this.data.rates.nbkz.prev.CNY));
						this.mainForm.ratesNbkzPrevUzsFld.setValue(this._r(this.data.rates.nbkz.prev.UZS));
					}
					if (this.data.rates.nbkz.last) {
						this.mainForm.ratesNbkzLastDayFld.setValue('<span style="color:#177ECB; font-weight:bold">' + this._r(this.data.rates.nbkz.last.day) + '</span>');
						this.mainForm.ratesNbkzLastUsdFld.setValue(this._r(this.data.rates.nbkz.last.USD));
						this.mainForm.ratesNbkzLastEurFld.setValue(this._r(this.data.rates.nbkz.last.EUR));
						this.mainForm.ratesNbkzLastRurFld.setValue(this._r(this.data.rates.nbkz.last.RUR));
						this.mainForm.ratesNbkzLastCnyFld.setValue(this._r(this.data.rates.nbkz.last.CNY));
						this.mainForm.ratesNbkzLastUzsFld.setValue(this._r(this.data.rates.nbkz.last.UZS));
					}
				}
			}

			this.mainForm.searchCountryCmb.select(0);
		}, this);
	},
	updateSelectedRowData: function (rowData) {
		this.selectedRowData = rowData
	},
	_r: function(value) {
		if (value && value.length)
			return value;
		else return '&nbsp;';
	}
});

Ext.define('kDesktop.transportation3.transportationsGridCheckColumn', {
    extend: 'Ext.grid.column.Column',

	checkedCount: 0,
	headerChecked: false,

    constructor: function(config) {
		config = config || {};

        this.addEvents(
			'beforecheckchange',
            'checkchange'
        );

		Ext.applyIf(config, {
			header: "<div class='x-form-field x-form-checkbox' style='margin: 0 auto'>&#160;</div>",
			width: 40,
			sortable: false,
			menuDisabled: true,
			hideable: false
		});

		kDesktop.transportation3.transportationsGridCheckColumn.superclass.constructor.call(this, config);
    },

    processEvent: function(type, view, cell, recordIndex, cellIndex, eventObj) {
		var me = this;
        if (type == 'mousedown' || (type == 'keydown' && (eventObj.getKey() == eventObj.ENTER || eventObj.getKey() == eventObj.SPACE))) {
            var record = view.panel.store.getAt(recordIndex),
                dataIndex = me.dataIndex,
                checked = !record.get(dataIndex);

			if (me.fireEvent('beforecheckchange', me, recordIndex, record, checked, eventObj) !== false) {
				if (checked) me.checkedCount++;
				else me.checkedCount--;

				record.set(dataIndex, checked);
				me.fireEvent('checkchange', me, recordIndex, record, checked, eventObj);
			}
            // cancel selection.
            return false;
        }
        else {
            return me.callParent(arguments);
        }
    },

    renderer: function(value, metaData, record, rowIdx, colIdx, store) {
        var cssPrefix = Ext.baseCSSPrefix,
            cls = [cssPrefix + 'grid-checkheader'];

        if (value) {
            cls.push(cssPrefix + 'grid-checkheader-checked');
        }
        return '<div class="' + cls.join(' ') + '">&#160;</div>';
    },

	setHeaderChecked: function(checked) {
		this.headerChecked = checked;
		if (this.headerChecked === true)
			this.setText("<div class='x-form-field x-form-checkbox' style='margin: 0 auto; background-position: 0 -13px;'>&#160;</div>");
		else
			this.setText("<div class='x-form-field x-form-checkbox' style='margin: 0 auto'>&#160;</div>");
	}
});

Ext.define('kDesktop.transportation3.createBill1cWnd2', {
	extend: 'Ext.window.Window',
	constructor: function(config) {
		config = config || {};

		this.ownerModule = config.ownerModule;
		this.parent = config.parent;

		this.mainForm = Ext.create('Ext.form.Panel', {
			border: false,
			frame: true,
			bodyStyle:'padding:5px;',
			items: [
				{
					xtype : 'container',
					layout: {
						type: 'hbox'
					},
					items: [
						{
							xtype: 'datefield',
							ref: 'date',
							width: 170,
							allowBlank: false,
							format: 'd.m.Y',
							editable: false,
							startDay: 1,
							value: new Date()
						}
					]
				}
			]
		});
		this.ownerModule.app.createReference(this.mainForm);

		Ext.applyIf(config, {
			title: 'Счет',
			width: 200,
			autoHeight: true,
			modal: true,
			plain: true,
			border: false,
			items: [
				this.mainForm
			],
			buttons: [
				{
					text: 'Cохранить',
					iconCls: 'ok-icon',
					scope: this,
					handler: function(){
						this.save();
					}
				},
				{
					text: 'Отмена',
					iconCls: 'close-icon',
					scope: this,
					handler: function(){
						this.close();
					}
				}
			]
		});

		kDesktop.transportation3.createBill1cWnd2.superclass.constructor.call(this, config);
	},

	save: function () {
		if (this.mainForm.getForm().isValid()) {
			Ext.MessageBox.confirm('Сохранение', 'Вы уверены что хотите сохранить эту запись?',
				function(btn){
					if(btn == 'yes') {
						this.ownerModule.app.doAjax({
							module: this.ownerModule.moduleId,
							method: 'createBill1c2',
							ids: Ext.encode(this.data),
							date: this.mainForm.date.getRawValue()
						},
						function(res) {
// 							this.parent.gridBbar.doRefresh();
// 							Ext.getCmp('transportation_taskgrid1_pt').doRefresh();
							this.parent.createBill1cModeUncheckColumn();
							this.close();
						},
						this, this);
					}
				},
				this
			);
		}
	},

	showMask: function(msg) {
		this.body.mask(msg + '...', 'x-mask-loading');
	},

	hideMask: function() {
		this.body.unmask();
	}
});

Ext.define('kDesktop.transportation3.transportations', {
	extend: 'Ext.panel.Panel',

	createBill1cMode: false,
	createBill1cModeClientId: 0,
	createBill1cModeClientNds: 0,
	createBill1cModeClientCurrency: '',

	constructor: function(config) {
		config = config || {};

		this.ownerModule = config.ownerModule;
		this.parent = config.parent
		this.gridLastScrollLeft = 0

		const TypesHelper = helpers.types
		const configData = config?.data ?? {}
		this.clientConfig = config?.clientConfig ?? {}
		this.priv = TypesHelper.isObjectAndHasProps(configData?.priv) ? configData.priv : {}
		this.permissions = TypesHelper.isObjectAndHasProps(configData?.permissions) ? configData.permissions : {}
		this.uid = 'transportation-transportations';
		this.title = 'Грузоперевозки';
		this.closable = false;

		this.filterPanel = Ext.create('kDesktop.transportation3.transportationsFilterPanel', {
			region: 'north',
			height: 328,
			collapsible: true,
			animCollapse: false,
			border: false,
			ownerModule: this.ownerModule,
			parent: this,
			data: config.data,
			clientConfig: this.clientConfig,
		})

		this.mon(this.filterPanel, 'onDealOpen', ({id = null, mode = '', setActive = false}) => {
			this.onDealOpen(id, mode, setActive)
		}, this)

		this.gridPanel = Ext.create('kDesktop.transportation3.transportationsGridPanel', {
			region: 'center',
			id: 'transportation_transportationsGridPanel',
			ownerModule: this.ownerModule,
			parent: this,
			data: config.data,
			clientConfig: this.clientConfig,
		})

		this.reestrMenu = Ext.create('Ext.menu.Menu', {
			items: [
				{
					text: 'Мир инструментов',
					scope: this,
					handler: function (){
						this.reestrData('masterTool');
					}
				},
				{
					text: 'Лотте',
					scope: this,
					handler: function (){
						this.reestrData('lotte');
					}
				}
			]
		});

		const showRegistry = this.clientConfig?.showRegistersInContextMenu &&
			!RolesHelper.isOperationHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATION_OPERATION, RolesHelper.OP_REGISTRY_NAME)
		/*this.gridPanel.on('afterrender', function() {
			this.gridPanel.grid.on('itemdblclick', function(view, rec, item, index, eventObj, options) {
				this.onDealOpen(rec.get('id'), 'edit', true, helpers.transportation.getTabTitleByDealItem(rec))
			}, this);
			this.gridPanel.grid.on('containercontextmenu', function(view, eventObj){
				var items = [];

				if (this.createBill1cMode) {
					items = [
						{
							text: 'ОТМЕНА',
							scope: this,
							handler: function (){
								this.disableCreateBill1cMode();
							}
						}
					];
					if(!RolesHelper.isOperationHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATION_OPERATION, RolesHelper.OP_BILL_NAME)){
						items.push({
							text: 'Выставить счет',
							scope: this,
							handler: function () {
								this.сreateBill1c2();
							}
						});
					}
				}
				else {
					items = [
						{
							text: 'Создать новую',
							scope: this,
							handler: function (){
								this.onDealOpen(null, 'new', true);
							}
						}
					];

					if ( this.priv && this.priv.transportation && this.priv.transportation.makeBill && !RolesHelper.isOperationHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATION_OPERATION, RolesHelper.OP_BILL_NAME)) items.push(
						'-',
						{
							//text: 'Выставить счет (по нескольким)',
							text: 'Выставить счет',
							scope: this,
							handler: function (){
								this.enableCreateBill1cMode();
							}
						}
					);

					if(!RolesHelper.isOperationHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATION_OPERATION, RolesHelper.OP_TRACING_NAME)){
						items.push(
							'-',
							{
								text: 'Трейсинг',
								scope: this,
								handler: function (){
									this.tracingData();
								}
							}
						)
					}

					if(!RolesHelper.isOperationHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATION_OPERATION, RolesHelper.OP_PERFORMANCE_NAME)){
						items.push(
							'-',
							{
								text: 'Performance',
								scope: this,
								handler: function (){
									this.performanceData();
								}
							}
						);
					}


					if (this.priv && this.priv.transportation && this.priv.transportation.modExport && !RolesHelper.isOperationHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATION_OPERATION, RolesHelper.OP_EXPORT_NAME)) items.push(
						'-',
						{
							text: 'Экспорт',
							scope: this,
							handler: function (){
								this.exportData();
							}
						}
					);
				}

				var _contextMenu = Ext.create('Ext.menu.Menu', { items: items } );
				_contextMenu.showAt(eventObj.getXY());
				eventObj.stopEvent();
			}, this);
			this.gridPanel.grid.on('itemcontextmenu',function(view, rec, node, index, eventObj) {
				var items = [];

				if (this.createBill1cMode) {
					items = [
						{
							text: 'ОТМЕНА',
							scope: this,
							handler: function () {
								this.disableCreateBill1cMode();
							}
						}
					];
					if(!RolesHelper.isOperationHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATION_OPERATION, RolesHelper.OP_BILL_NAME)){
						items.push({
							text: 'Выставить счет',
							scope: this,
							handler: function () {
								this.сreateBill1c2();
							}
						});
					}
				}
				else {
					const id = rec.get('id')
					items = [
						{
							text: 'Создать новую',
							scope: this,
							handler: function (){
								this.onDealOpen(null, 'new', true);
							}
						},
						'-',
						{
							text: 'Редактировать',
							scope: this,
							handler: function (){
								this.onDealOpen(id, 'edit', true, helpers.transportation.getTabTitleByDealItem(rec));
							}
						},
					];

					if (this.priv && this.priv.transportation && this.priv.transportation.copy) items.push(
						'-',
						{
							text: 'Копировать',
							scope: this,
							handler: function (){
								this.onDealOpen(id, 'copy', true);
							}
						}
					);

					items.push(
						'-',
						{
							text: 'Доп-заявка',
							scope: this,
							handler: function (){
								this.onDealOpen(id, 'multi', true);
							}
						}
					);

					if ( this.priv && this.priv.transportation && this.priv.transportation.modDelete ) {
						if (rec.get('status') == 1 && !RolesHelper.isOperationHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATION_OPERATION, RolesHelper.OP_DELETE_NAME)) items.push(
							'-',
							{
								text: 'УДАЛИТЬ',
								scope: this,
								handler: function (){
									Ext.MessageBox.confirm('Удалить?', 'Вы уверены что хотите удалить?',
										function(btn){
											if (btn === 'yes') {
												this.ownerModule.app.doAjax({
														module: this.ownerModule.moduleId,
														method: 'transpDel',
														id
													},
													function() {
														const tabToClose = this.ownerModule.getTabItem(id)
														if (tabToClose) {
															this.ownerModule.remove(tabToClose, true)
														}
														helpers.transportation.onTransportationTabClose(id)
														this.gridPanel.gridBbar.doRefresh()
													},
													this, this)
											}
										},
										this
									);
								}
							}
						);
						else if (rec.get('status') == 0 && !RolesHelper.isOperationHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATION_OPERATION, RolesHelper.OP_DELETE_NAME)) items.push(
							'-',
							{
								text: 'ВОССТАНОВИТЬ',
								scope: this,
								handler: function (){
									Ext.MessageBox.confirm('Восстановить?', 'Вы уверены что хотите восстановить?',
										function(btn){
											if(btn == 'yes') {
												this.ownerModule.app.doAjax({
														module: this.ownerModule.moduleId,
														method: 'transpRestore',
														id: rec.get('id')
													},
													function(res) {
														this.gridPanel.gridBbar.doRefresh();
													},
													this, this);
											}
										},
										this
									);
								}
							}
						);
					}

					if ( this.priv && this.priv.transportation && this.priv.transportation.setUnloadChecked && rec.get('offload_str') && (rec.get('offload_str').length > 0) && (rec.get('offloadchecked') != 1) && !RolesHelper.isOperationHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATION_OPERATION, RolesHelper.OP_CONFIRM_UNLOADING_DATE_NAME) ) items.push(
						'-',
						{
							text: 'Подтвердить дату выгрузки',
							scope: this,
							handler: function (){
								Ext.MessageBox.confirm('Подтвердить?', 'Подтвердить дату '+rec.get('offload_str')+' для перевозки '+rec.get('idstr')+'?',
									function(btn){
										if(btn == 'yes') {
											this.ownerModule.app.doAjax({
													module: this.ownerModule.moduleId,
													method: 'setUnloadChecked',
													id: rec.get('id')
												},
												function(res) {
													this.gridPanel.gridBbar.doRefresh();
												},
												this, this);
										}
									},
									this
								);
							}
						}
					);
					items.push(
						'-',
						{
							text: 'Создать задачу',
							iconCls: 'add-icon',
							scope: this,
							handler: () => {
								Ext.create('transpPanels.tasks.createTaskModal', {
									dealId: rec.get('id') ?? null,
									multimodalId: rec.get('multimodal') === '0'
										? null
										: rec.get('multimodal_id') + '-' + rec.get('multimodal_num'),
									parent: this,
								}).show()
							}
						}
					)

					if (!RolesHelper.isOperationHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATION_OPERATION, RolesHelper.OP_APPLICATION_FOR_CARGO_INSURANCE_NAME)) {
						items.push(
							'-',
							{
								text: 'Заявление на страхование груза',
								scope: this,
								handler: function () {
									Ext.MessageBox.confirm('Подтвердить?', 'Создать заявление на страхование груза?',
										function (btn) {
											if (btn === 'yes') {
												Ext.Msg.wait('Загрузка данных...', 'Пожалуйста, подождите')
												Ext.Ajax.request({
													url: 'index.php',
													params: {
														module: this.ownerModule.moduleId,
														method: 'makeCargoInsuranceRequest',
														id: rec.get('id'),
													},
													success: () => Ext.Msg.hide(),
													failure: (response) => {
														Ext.Msg.hide()
														const errorResponseData = Ext.decode(response.responseText)
														const errorText = 'Ошибка при создании заявления на страхование груза: ' + errorResponseData?.msg
														console.error(errorText)
														Ext.Msg.alert('Ошибка', errorText)
													}
												})
											}
										},
										this
									);
								}
							}
						);
					}
					if(!RolesHelper.isOperationHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATION_OPERATION, RolesHelper.OP_WAYBILL_NAME)){
						items.push(
							'-',
							{
								text: 'Транспортная накладная',
								scope: this,
								handler: function (){
									this.makeTN(rec.get('id'))
								}
							}
						);
					}

					if ( this.priv && this.priv.transportation && this.priv.transportation.makeBill && !RolesHelper.isOperationHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATION_OPERATION, RolesHelper.OP_BILL_NAME) ) items.push(
						'-',
						{
							//text: 'Выставить счет (по нескольким)',
							text: 'Выставить счет',
							scope: this,
							handler: function (){
								this.enableCreateBill1cMode();
							}
						}
					);

					if(!RolesHelper.isOperationHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATION_OPERATION, RolesHelper.OP_TRACING_NAME)){
						items.push(
							'-',
							{
								text: 'Трейсинг',
								scope: this,
								handler: function (){
									this.tracingData();
								}
							}
						);
					}

					if(showRegistry){
						items.push(
							'-',
							{
								text: 'Реестр',
								scope: this,
								menu: this.reestrMenu
							},
						);
					}

					if(!RolesHelper.isOperationHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATION_OPERATION, RolesHelper.OP_PERFORMANCE_NAME)){
						items.push(
							'-',
							{
								text: 'Performance',
								scope: this,
								handler: function (){
									this.performanceData();
								}
							}
						);
					}


					if (this.priv && this.priv.transportation && this.priv.transportation.modExport && !RolesHelper.isOperationHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATION_OPERATION, RolesHelper.OP_EXPORT_NAME)) items.push(
						'-',
						{
							text: 'Экспорт',
							scope: this,
							handler: function (){
								this.exportData();
							}
						}
					);
				}

				var _contextMenu = Ext.create('Ext.menu.Menu', { items: items } );
				_contextMenu.showAt(eventObj.getXY());
				eventObj.stopEvent();
			}, this);
			this.gridPanel.grid.on('select', function(sm, record, rowIndex, eventObj){
			}, this);
			this.gridPanel.checkColumn.on('beforecheckchange', function(col, index, record, checked, eventObj) {
				return this.onCheckColumnBeforeClick(col, index, record, checked, eventObj);
			}, this);
			this.gridPanel.checkColumn.on('checkchange', function(col, index, record, checked, eventObj) {
				return this.onCheckColumnClick(col, index, record, checked, eventObj);
			}, this);
			this.gridPanel.grid.headerCt.on('headerclick', function(grid, col, eventObj) {
				if (col.dataIndex && (col.dataIndex == 'selectionModelChecked')) this.onCheckColumnHeaderClick();
			}, this);
		}, this)*/

		Ext.applyIf(config, {
			border: false,
			layout: 'border',
			items: [
				this.filterPanel,
				this.gridPanel
			]
		});

		this.callParent(arguments);

/*		this.on('activate', function () {
			this.gridPanel.reCreateScroll()

			Ext.defer(() => {
				const grid = this.gridPanel.grid

				const scrollerEl = grid.el.down('.x-scroller-horizontal .x-scroller-ct')
				if (!scrollerEl || !scrollerEl?.dom)
					return false

				scrollerEl.dom.scrollLeft = this.gridLastScrollLeft
			}, 100, this)
		}, this)*/

		/*this.on('afterrender', function () {
			// Устанавливаем ширину колонок, если есть сохранённое состояние
			const state = this.gridPanel.grid.getState()
			if (state && state.columns && state.columns.length) {
				for (let i = 0; i < state.columns.length; i++) {
					const col = state.columns[i]
					if (col && col.width && col.width > 0 && this.gridPanel.grid.columns[i]) {
						this.gridPanel.grid.columns[i].setWidth(col.width)
					}
				}
			}

			const newDealData = config?.newDealData ?? {}
			const hasNewDealData = helpers.types.isObjectAndHasProps(newDealData)
			const DealHelper = helpers.transportation
			let tabs = DealHelper.getTabListLocalStorage()
			if (!hasNewDealData) {
				tabs = DealHelper.removeEmptyTabs(tabs)
			} else {
				// Создаём новую вкладку для новой сделки, пока что передача данных
				// для новой сделки идет только из Трекера
				tabs.forEach(tab => tab.active = false)
				tabs.push({id: "new", active: true, multiId: null})
				this.onDealOpen(null, 'new', true, 'Новая грузоперевозка', newDealData)
			}

			if (!TypesHelper.isArrayWithLength(tabs))
				return false

			tabs.forEach(tab => {
				if (tab?.mode !== 'new') {
					this.onDealOpen(tab.id, 'edit', false)
				}
			})

			// Устанавливаем активную вкладку из localStorage
			const activeTabData = tabs.find(tab => tab.active)
			if (activeTabData) {
				const activeTab = this.ownerModule.getTabItem(activeTabData.id)
				if (!TypesHelper.isObjectAndHasProps(activeTab)) {
					return;
				}

				this.ownerModule.setActiveTab(activeTab)
			}

			Ext.defer(() => {
				const scrollerEl = this.gridPanel.grid.el.dom.querySelector('.x-scroller-horizontal .x-scroller-ct')
				if (!scrollerEl)
					return false

				const onScrollBuffered = Ext.Function.createBuffered(() => {
					this.gridLastScrollLeft = scrollerEl?.scrollLeft ?? 0
				}, 200)
				scrollerEl.addEventListener('scroll', onScrollBuffered)
			}, 200, this)
		}, this)*/
	},
	enableCreateBill1cMode: function() {
		this.createBill1cMode = true;
		// this.gridPanel.checkColumn.show();

		// this.gridPanel.grid.getHorizontalScroller().setScrollLeft(0);
	},

	disableCreateBill1cMode: function() {
		this.createBill1cMode = false;
		this.createBill1cModeClientId = 0;
		this.createBill1cModeClientNds = 0;
		this.createBill1cModeClientCurrency = '';

		// this.gridPanel.checkColumn.hide();
		this.createBill1cModeUncheckColumn();
	},

	сheckOnlyOnePageCount: function() {
		/*if (this.gridPanel.store.getTotalCount() > this.gridPanel.store.pageSize) {
			Ext.MessageBox.alert('', 'Воспользуйтесь фильтром или выберите большее количество записей на странице, чтобы записи помещались на одной странице.');
			return false;
		}*/

		return true;
	},

	onCheckColumnBeforeClick: function(col, index, record, checked, eventObj) {
		/*if (this.сheckOnlyOnePageCount() === false)
			return false

		if (checked && (this.gridPanel.checkColumn.checkedCount > 0)) {
			if (record.get('client') !== this.createBill1cModeClientId)
				return false
			if (record.get('clientnds') !== this.createBill1cModeClientNds)
				return false
			if (record.get('client_currency') !== this.createBill1cModeClientCurrency)
				return false
		}

		const store = this.gridPanel.store
		const isSplitInvoice = record.get('split_invoice') === true

		// Если текущая запись имеет split_invoice=true
		if (isSplitInvoice) {
			for (let i = 0; i < store.getCount(); i++) {
				const rec = store.getAt(i)
				if (rec !== record && rec.get('selectionModelChecked') === true) {
					Ext.Msg.alert('Ограничение', 'Нельзя выбрать сделку с разбивкой счета, если уже выбраны другие сделки')
					return false
				}
			}
		} else {
			// Если текущая запись обычная, но уже выбрана запись с split_invoice=true
			for (let i = 0; i < store.getCount(); i++) {
				const rec = store.getAt(i)
				if (rec.get('split_invoice') === true && rec.get('selectionModelChecked') === true) {
					Ext.Msg.alert('Ограничение', 'Нельзя выбрать сделку, если уже выбрана сделка с разбивкой счета')
					return false
				}
			}
		}*/

		return true
	},

	onCheckColumnClick: function(col, index, record, checked, eventObj) {
		/*if (this.gridPanel.checkColumn.checkedCount == 0) {
			this.createBill1cModeClientId = 0;
			this.createBill1cModeClientNds = 0;
			this.createBill1cModeClientCurrency = '';
		}
		else if (this.gridPanel.checkColumn.checkedCount == 1) {
			this.createBill1cModeClientId = record.get('client');
			this.createBill1cModeClientNds = record.get('clientnds');
			this.createBill1cModeClientCurrency = record.get('client_currency');
		}*/
	},

	onCheckColumnHeaderClick: function(col, index, record, eventObj) {
		/*if ((!this.gridPanel.checkColumn.headerChecked) && (this.сheckOnlyOnePageCount() === false)) return false;

		if (!this.gridPanel.checkColumn.headerChecked) {
			if (this.gridPanel.store.getCount()) {
				if (this.gridPanel.checkColumn.checkedCount == 0) {
					var record = this.gridPanel.store.getAt(0);
					record.set('selectionModelChecked', true);
					this.gridPanel.checkColumn.checkedCount++

					this.createBill1cModeClientId = record.get('client');
					this.createBill1cModeClientNds = record.get('clientnds');
					this.createBill1cModeClientCurrency = record.get('client_currency');
				}

				for(var i=0; i<this.gridPanel.store.getCount(); i++) {
					record = this.gridPanel.store.getAt(i);

					if ( (this.createBill1cModeClientId == record.get('client')) && (this.createBill1cModeClientNds == record.get('clientnds')) && (this.createBill1cModeClientCurrency == record.get('client_currency')) ) {
						if (record.get('selectionModelChecked') !== true) {
							record.set('selectionModelChecked', true);
							this.gridPanel.checkColumn.checkedCount++
						}
					}
				}

				this.gridPanel.checkColumn.setHeaderChecked(true);
			}
		}
		else {
			this.createBill1cModeUncheckColumn();
			this.gridPanel.checkColumn.setHeaderChecked(false);
		}*/
	},
	createBill1cModeUncheckColumn: function() {
		/*if (this.gridPanel.store.getCount()) for(var i=0; i<this.gridPanel.store.getCount(); i++) {
			var record = this.gridPanel.store.getAt(i);
			if (record.get('selectionModelChecked') === true) record.set('selectionModelChecked', false);
		}
		this.createBill1cModeClientId = 0;
		this.createBill1cModeClientNds = 0;
		this.createBill1cModeClientCurrency = '';
		this.gridPanel.checkColumn.checkedCount = 0;*/
	},
	сreateBill1c2: function() {
		/*if (this.gridPanel.checkColumn.checkedCount == 0) {
			Ext.MessageBox.alert('Ошибка', 'Не выбрано ни одной записи');
			return;
		}

		var data = [];
		for(var i=0; i<this.gridPanel.store.getCount(); i++) {
			var rec = this.gridPanel.store.getAt(i);

			if (rec && (rec.get('selectionModelChecked') === true)) data.push( rec.get('id') );
		}

		Ext.create('kDesktop.transportation3.createBill1cWnd2', { ownerModule: this.ownerModule, parent: this, data: data }).show();*/
	},
	onDealOpen: function(tid, mode, setActive = true, title = '', newDealData = {}) {
		const DealHelper = helpers.transportation

		// Сохраняем оригинальный id для копии/доп-заявки
		const originalId = (mode === 'copy' || mode === 'multi') ? tid : null
		tid = (mode === 'copy' || mode === 'multi') ? 'new' : (tid || 'new')

		const existingTab = this.ownerModule.items.findBy(function(item) {
			return item.tid === tid || (tid === 'new' && item.tid === 'new')
		})

		if (existingTab) {
			if (setActive) {
				this.ownerModule.setActiveTab(existingTab)
				DealHelper.onTransportationTabOpen(tid)
			}

			return existingTab
		}

		const tabs = DealHelper.getTabListLocalStorage()
		const tabData = tabs.find(tab => tab.id === tid.toString())
		const multiId =  tabData?.multiId ?? null

		let params = {
			ownerModule: this.ownerModule,
			permissions: this.permissions,
			clientConfig: this.clientConfig,
			tid,
			multiId, // Передаем multiId для формирования корректного тайтла доп-заявок (из localStorage)
			originalId, // Передаем оригинальный id для копирования/доп-заявки
			mode,
			closable: true,
			dataLoaded: false,
			listeners: {
				activate: function() {
					if (!this.dataLoaded) {
						this.loadDealData(newDealData)
						this.dataLoaded = true
					}
					DealHelper.onTransportationTabOpen(tid)
				},
				beforeclose: function() {
					DealHelper.onTransportationTabClose(tid)
				}
			}
		}

		if (title) {
			params = {
				...params,
				title
			}
		}
		const newTab = Ext.create('kDesktop.transportation3.dealTab', params)
		this.ownerModule.add(newTab)

		if (setActive) {
			this.ownerModule.setActiveTab(newTab)
		}

		return newTab
	},
	makeTN: function(id) {
		var url = this.ownerModule.app.connectUrl+'?module='+this.ownerModule.moduleId+'&method=makeTN&id='+id;
		window.open(url, "download");
	},

	exportData: function() {
		var url = this.ownerModule.app.connectUrl+'?module='+this.ownerModule.moduleId+'&method=exportData&filtr='+this.gridPanel.store.proxy.extraParams.filtr;
		window.open(url, "download");
	},

	tracingData: function() {
		var url = this.ownerModule.app.connectUrl+'?module='+this.ownerModule.moduleId+'&method=tracingData&type=main&filtr='+this.gridPanel.store.proxy.extraParams.filtr;
		window.open(url, "download");
	},

	reestrData: function(type) {
		if (this.сheckOnlyOnePageCount() === false) return false;

		var url = this.ownerModule.app.connectUrl+'?module='+this.ownerModule.moduleId+'&method=reestrData&type='+type+'&filtr='+this.gridPanel.store.proxy.extraParams.filtr;
		window.open(url, "download");
	},

	performanceData: function() {
		var url = this.ownerModule.app.connectUrl+'?module='+this.ownerModule.moduleId+'&method=performanceData&filtr='+this.gridPanel.store.proxy.extraParams.filtr;
		window.open(url, "download");
	},

	showMask: function(msg) {
		this.body.mask(msg + '...', 'x-mask-loading');
	},

	hideMask: function() {
		this.body.unmask();
	}
});

// Модальное окно со сделками у которых дата выгрузки не подтверждена, активируется в шаблоне index.tpl
// Временно отключено, при активации надо будет твикнуть компонент, чтобы эта модалка вписывалась в новый подход
// к открытию вкладок сделок
/*Ext.define('kDesktop.transportation3.transportationsUnloadCheckWnd', {
	extend: 'Ext.window.Window',
	moduleId: 'transportation3',
	constructor: function(config) {
		config = config || {};
		this.app = config.app;
		this.clientConfig = helpers.types.isObjectAndHasProps(config?.clientConfig) ? config?.clientConfig : {}
		this.gridPanel = Ext.create('kDesktop.transportation3.transportationsGridPanel', {
			id: 'transportation_transportationsUnloadCheckWnd',
			unloadCheckFilter: 1,
			ownerModule: this,
			parent: this,
		});
		this.gridPanel.grid.on('itemdblclick', (view, rec, item, index, eventObj, options) =>
			this.onDealOpen(rec.get('id'), 'edit', null, this.clientConfig))

		this.gridPanel.grid.on('containercontextmenu', function(view, eventObj){
			eventObj.stopEvent();
		}, this);
		this.gridPanel.grid.on('itemcontextmenu',function(view, rec, node, index, eventObj) {
			const items = [
				{
					text: 'Редактировать',
					scope: this,
					handler: () => this.onDealOpen(rec.get('id'), 'edit', null, this.clientConfig)
				},
			];

			if ( rec.get('offload_str') && (rec.get('offload_str').length > 0) && (rec.get('offloadchecked') != 1) ) items.push(
				'-',
				{
					text: 'Подтвердить дату выгрузки',
					scope: this,
					handler: function (){
						Ext.MessageBox.confirm('Подтвердить?', 'Подтвердить дату '+rec.get('offload_str')+' для перевозки '+rec.get('idstr')+'?',
							function(btn){
								if(btn === 'yes') {
									this.app.doAjax({
										module: this.moduleId,
										method: 'setUnloadChecked',
										id: rec.get('id')
									},
									function(res) {
										this.gridPanel.gridBbar.doRefresh();
									},
									this, this);
								}
							},
							this
						);
					}
				}
			);

			const _contextMenu = Ext.create('Ext.menu.Menu', { items: items } );
			_contextMenu.showAt(eventObj.getXY());
			eventObj.stopEvent();
		}, this);

		Ext.applyIf(config, {
			title: 'Подтвердите дату выгрузки',
			width: 900,
			height: 500,
			modal: true,
			plain: true,
			border: false,
			maximizable: true,
			layout: 'fit',
			items: [
				this.gridPanel
			],
			buttons: [
				{
					text: 'Закрыть',
					scope: this,
					handler: function(){
						this.close();
					}
				}
			],
			listeners: {
				afterrender: function() {
					Ext.transpUnloadCardShown = true;
				},
				beforeclose: function() {
					Ext.transpUnloadCardShown = false;
				}
			}
		});

		kDesktop.transportation3.transportationsUnloadCheckWnd.superclass.constructor.call(this, config);
	},
/*	onDealOpen: function(oid, mode, type, clientConfig = {}) {
		this.app.doAjax({
			module: this.moduleId,
			method: 'transpData',
			mode: mode,
			id: (this.mode === 'new') ? 0 : oid
		},
		function(res) {
			Ext.create('kDesktop.transportation3.transpEdit.fromUnloadCheckWnd', {
				ownerModule: this,
				parent: this,
				oid: oid,
				data: res,
				mode: mode,
				clientConfig: clientConfig
			}).show();
		},
		this, this);
	},
	showMask: function(msg) {
		this.body.mask(msg + '...', 'x-mask-loading');
	},
	hideMask: function() {
		this.body.unmask();
	}
});*/

Ext.define('kDesktop.transportation3.transpEdit', {
	extend: 'Ext.panel.Panel',
	constructor: function(config) {
		config = config || {};
		const RolesHelper = helpers.roles
		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.permissions = config?.permissions ?? {}
		this.clientConfig = config?.clientConfig ?? {}
		this.closable = true;
		this.oid = config.oid;
		this.data = config.data;
		this.mode = config.mode;
		this.priv = (config.data && config.data.priv) ? config.data.priv : null;
		const newDealData = config?.newDealData ?? {}

		this.subDealsPanel = null
		this.linkedDeals = this.data.linkedDeals ?? []
		this.isMultimodalParent = helpers.subDeal.checkLinkedIsParent(this.linkedDeals)

		this.generalPanel = Ext.create('kDesktop.transportation3.transpEdit.generalPanel', {
			title: 'Общие',
			hidden: RolesHelper.isTransportationTabHidden(this.permissions, RolesHelper.TAB_MAIN_NAME),
			ownerModule: this.ownerModule,
			parent: this,
			clientConfig: this.clientConfig,
		});

		this.clientPanel = Ext.create('kDesktop.transportation3.transpEdit.clientPanel', {
			title: 'Клиент',
			hidden: RolesHelper.isTransportationTabHidden(this.permissions, RolesHelper.TAB_CLIENT_NAME),
			ownerModule: this.ownerModule,
			parent: this,
			permissions: this.permissions,
		});

		this.loadUnloadPanel = Ext.create('kDesktop.transportation3.transpEdit.loadUnloadPanel', {
			title: 'Загрузки/Выгрузки',
			hidden: RolesHelper.isTransportationTabHidden(this.permissions, RolesHelper.TAB_DOWNLOADS_UPLOADS_NAME),
			ownerModule: this.ownerModule,
			parent: this,
			newDealData
		});

		this.ferryPanel = Ext.create('kDesktop.transportation3.transpEdit.ferryPanel', {
			title: 'Подрядчик',
			hidden: RolesHelper.isTransportationTabHidden(this.permissions, RolesHelper.TAB_FERRYMAN_NAME),
			ownerModule: this.ownerModule,
			parent: this,
			permissions: this.permissions,
			clientConfig: this.clientConfig,
		});

		this.docPanel = Ext.create('kDesktop.transportation3.transpEdit.docPanel', {
			title: 'Документы',
			hidden: RolesHelper.isTransportationTabHidden(this.permissions, RolesHelper.TAB_DOCS_NAME),
			ownerModule: this.ownerModule,
			parent: this,
			permissions: this.permissions,
			clientConfig: this.clientConfig,
		});

		this.financePanel = Ext.create('kDesktop.transportation3.transpEdit.financePanel', {
			title: 'Платежи',
			hidden: RolesHelper.isTransportationTabHidden(this.permissions, RolesHelper.TAB_PAYMENTS_NAME),
			ownerModule: this.ownerModule,
			parent: this,
			permissions: this.permissions,
		});

		this.reportPanel = Ext.create('kDesktop.transportation3.transpEdit.reportPanel', {
			title: 'Отчеты',
			hidden: RolesHelper.isTransportationTabHidden(this.permissions, RolesHelper.TAB_REPORTS_NAME),
			ownerModule: this.ownerModule,
			parent: this
		});

		this.finePanel = Ext.create('kDesktop.transportation3.transpEdit.finePanel', {
			title: 'Штрафы',
			hidden: RolesHelper.isTransportationTabHidden(this.permissions, RolesHelper.TAB_FINETOCLIENT_NAME),
			ownerModule: this.ownerModule,
			parent: this
		});

		this.calcPanel = Ext.create('kDesktop.transportation3.transpEdit.calcPanel', {
			title: 'Расчеты',
			hidden: RolesHelper.isTransportationTabHidden(this.permissions, RolesHelper.TAB_CALCULATIONS_NAME),
			ownerModule: this.ownerModule,
			parent: this
		});

		const items = [
			this.generalPanel,
			this.clientPanel,
			this.loadUnloadPanel,
			this.ferryPanel,
			this.financePanel,
			this.docPanel,
			this.finePanel,
			this.reportPanel,
			// this.surveerPanel,
			this.calcPanel,
		];

		// Если есть права на просмотр вкладки Лог - инициализируем ее
		const showLogsTab = !RolesHelper.isTransportationTabHidden(this.permissions, RolesHelper.TAB_LOG_NAME)
		if (showLogsTab) {
			items.push(Ext.create('deal.logPanel', {
				tid: this.oid
			}))
		}

		// Если есть права на просмотр вкладки Сводная таблица и это главная сделка - инициализируем вкладку
		const showSummaryTab = !RolesHelper.isTransportationTabHidden(this.permissions, RolesHelper.TAB_SUMMARY_TABLE_NAME) && this.isMultimodalParent
		if (showSummaryTab) {
			this.subDealsPanel = Ext.create('deal.subDealsPanel', {
				title: 'Сводная таблица',
				parent: this,
				tid: this.oid,
			})

			items.push(this.subDealsPanel)
		}

		this.tabPanel = Ext.create('Ext.tab.Panel', {
			border: true,
			layout: 'fit',
			activeItem: 0,
			items: items,
			bbar: {
				cls: 'custom-bbar',
				items: [
					'->',
					{
						text: 'Сохранить',
						iconCls: 'ok-icon',
						scope: this,
						hidden: (this.data.allowed && (this.data.allowed.allowedCount === 0)) ? true : false,
						handler: function () {
							this.save()
						}
					},
					{
						text: 'Закрыть',
						iconCls: 'close-icon',
						scope: this,
						handler: () => {
							helpers.transportation.onTransportationTabClose(this.oid);
							this.parent.close();
						}
					}
				]
			},
			listeners: {
				tabchange: (tabPanel, newCard) => {
					const fullWidthTabs = ['loadUnloadPanel', 'subDealsPanel']
					const fullWidth = fullWidthTabs.includes(newCard.xtype)

					// Установка ширины для футера с кнопками сохранить и закрыть
					const bbarElement = document.querySelector('.custom-bbar')

					if (bbarElement) {
						if (fullWidth) {
							bbarElement.classList.add('full-width')
						} else {
							bbarElement.classList.remove('full-width')
						}
					}

					// Установка ширины для текущей вкладки
					const newWidth = fullWidth ? '100%' : 920
					newCard.setWidth(newWidth)
				}
			}
		})

		this.mainForm = Ext.create('Ext.form.Panel', {
			width: '100%',
			layout: 'fit',
			border: false,
			frame: false,
			items: [
				this.tabPanel
			],
		});

		this.sdoctplMenu = Ext.create('Ext.menu.Menu', { items: [] });

		const me = this

		Ext.applyIf(config, {
			border: false,
			tbar: [
				{
					xtype: 'container',
					cls: 'custom-toolbar',
					html: `
							<div class="custom-toolbar-container">
								<button
									class="default-button-inside-tbar"
									onclick="window.transportationInstance.downloadDocuments()"
								>
									Скачать документы
								</button>
								${
										this.linkedDeals.length > 0
											? `
											<span class="custom-toolbar-text">
												Связанные сделки:
											</span>
											${this.linkedDeals
												.map(
													deal => `
														<button 
															class="default-button-inside-tbar"
															style="margin-bottom: 2px" 
															onclick="window.transportationInstance.handleLinkedDeal(${deal?.tid})"
														>
															${deal?.title ?? ''} (${deal?.vehicleType ?? ''})
														</button>
													`
												)
												.join('')}
										`
											: ''
									}
							</div>
							`
				}
			],
			layout: {
				type: 'hbox',
				align: 'stretch'
			},
			items: [
				this.mainForm
			]
		})

		window.transportationInstance = me

		kDesktop.transportation3.transpEdit.superclass.constructor.call(this, config);

		this.on('afterrender', function() {
			if (this.data) this.loadData();
		}, this);

		// this.on('destroy', function(o, eOpts) {
		// 	if (this.fromUnloadCheckWnd) this.parent.close();
		// }, this);
	},
	handleLinkedDeal: function(tid) {
		if (helpers.types.isNull(tid))
			return false

		const deal = this.linkedDeals.filter(deal => deal?.tid.toString() === tid.toString())[0]
		if (!helpers.types.isObjectAndHasProps(deal))
			return false

		const targetComponent = Ext.ComponentQuery.query('#dealsGridTab')?.[0]
		if (!targetComponent)
			return false

		const tabNumFormatted = deal?.title.replace(/\s+/g, '') ?? ''
		targetComponent.onDealOpen(tid.toString(), 'edit', true, `Грузоперевозка ${tabNumFormatted}`)
	},
	downloadDocuments: function() {
		const activeTab = this.ownerModule.getActiveTab() ?? {}
		const oid = activeTab?.tid ?? null

		if (!oid) {
			Ext.Msg.alert('Ошибка', 'Не удалось определить ID сделки')
			return false
		}

		const templates = this.data?.sdoctpl ?? []
		if (!helpers.types.isArrayWithLength(templates)) {
			Ext.Msg.alert('Нет шаблонов', 'Список шаблонов пуст')
			return false
		}

		Ext.create('kDesktop.downloadDocsModal', {
			oid,
			templates: templates
		}).show()
	},
	loadData: function() {
		const RolesHelper = helpers.roles
		if (this.data.userList) this.generalPanel.logistCmb.store.loadData(this.data.userList);
		if (this.data.managerList) this.generalPanel.managerCmb.store.loadData(this.data.managerList);
		if (this.data.statusDict) this.generalPanel.statusCmb.store.loadData(this.data.statusDict);

		const transportTypeList = this.clientConfig?.transportTypeList ?? {}
		if (helpers.types.isObjectAndHasProps(transportTypeList)) {
			this.clientPanel.typetsCmb.store.loadData(helpers.data.convertObjectToStoreData(transportTypeList))
		}

		if (helpers.types.isArrayWithLength(this.data?.regionDict)) {
			this.clientPanel.regionCmb.store.loadData(this.data.regionDict)
		}

		if (!RolesHelper.isFieldHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS, 'client_sns')) {
			this.clientPanel.clientSnsFld.setValue('Платежный баланс: ' + this._s(this.data.data.client_sns))
			this.clientPanel.doLayout()
		}

		if (!RolesHelper.isFieldHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS, 'client_accountant')) {
			this.clientPanel.clientAccountantFld.setValue('Бухгалтер: ' + this._s(this.data.data.client_accountant));
		}

		if (!RolesHelper.isFieldHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS, 'ferry_sns')) {
			this.ferryPanel.ferrySnsFld.setValue('Платежный баланс: ' + this._s(this?.data?.data?.ferry_sns));
			this.ferryPanel.doLayout()
		}
		if (!RolesHelper.isFieldHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS, 'clientdocdelivery') && this.data.clientDocdeliveryDict) {
			this.docPanel.mainPanel.clientDocdeliveryCmb.store.loadData(this.data.clientDocdeliveryDict);
		}

		const isCarrierHidden = RolesHelper.isFieldHidden(
			this.permissions,
			RolesHelper.RESOURCE_TRANSPORTATIONS,
			'ferryman_name'
		)

		if (!isCarrierHidden && this.ferryPanel) {
			this.initCarrierStores()
		}

		/*if (this.data.survDocTypeDict) this.surveerPanel.docTypeStore.loadData(this.data.survDocTypeDict);
		if (this.data.ferrymanList) this.surveerPanel.mainPanel.survCrmCompanyCmb.store.loadData(this.data.ferrymanList); */

		if (this.data.loadGrid) this.loadUnloadPanel.loadStore.loadData(this.data.loadGrid);
		if (this.data.unloadGrid) this.loadUnloadPanel.unloadStore.loadData(this.data.unloadGrid);

		if (this.data.data) {
			this.multimodal_id = this.data.data.multimodal_id;
			this.mainForm.getForm().setValues(this.data.data);
		}

		// Инициализация split_invoice вручную (даже если вкладка не была открыта)
		// Определяем начальное значение для чекбокса Выставлять счёт с разбивкой, также дизэблим его
		// если нет значения > 0 хотя бы у одного инпута с пробегом
		const checkbox = this.loadUnloadPanel.down('#splitInvoiceCheckbox')
		if (checkbox) {
			const runRus = Ext.Number.from(this.data.data.run_rus, 0)
			const runForeign = Ext.Number.from(this.data.data.run_foreign, 0)
			const allowSplit = runRus > 0 && runForeign > 0

			const splitInvoice = this.data.data?.split_invoice === 't'
				|| this.data.data?.split_invoice === true
				|| this.data.data?.split_invoice === 'true'

			checkbox.setDisabled(!allowSplit)
			checkbox.setValue(allowSplit && splitInvoice)
		}

		if (this.mode === 'new') this.data.data = {}
	},
	initCarrierStores: function() {
		/* Создаем сторы для селекторов машин и водителей,
		до того, как будет открыта вкладка Перевозчик,
		т.о если будет нажата кнопка "Сохранить" до этого,
		(а вкладка вообще может не быть открыта => перетрутся данные)
		данные по машине и водителю будут уже загружены */
		const tData = this.data?.data ?? {}
		const carrierId = tData?.ferryman ?? null
		const carId = tData?.ferrycar_id ?? null
		const driverId = tData?.driver_id ?? null

		this.ferryPanel.driverFioStore = helpers.drivers.createStore(
			{
				permissions: this.permissions,
				carrierId,
				selectedId: driverId,
				autoLoad: false
			}
		)

		const driverFioStore = this.ferryPanel.driverFioStore
		if (this.ferryPanel?.driverFioSelect) {
			this.ferryPanel.driverFioSelect.bindStore(driverFioStore)
		}

		if (driverId) {
			this.ferryPanel.driverFioSelect.setValue(driverId)
			driverFioStore.load()
		}

		this.ferryPanel.carLicensePlateNumbersStore = helpers.cars.createStore(
			{
				permissions: this.permissions,
				carrierId,
				selectedId: carId,
				autoLoad: false
			}
		)

		const carLicensePlateNumbersStore = this.ferryPanel.carLicensePlateNumbersStore
		if (this.ferryPanel?.carLicensePlateNumberSelect) {
			this.ferryPanel.carLicensePlateNumberSelect.bindStore(carLicensePlateNumbersStore)
		}

		if (!carId)
			return false

		this.ferryPanel.carLicensePlateNumberSelect.setValue(carId)
		carLicensePlateNumbersStore.load()
	},
	_s: function(value) {
		if (value) return value;
		else return '';
	},
	save: function () {
		this.loadUnloadPanel.loadGridEditPlugin.completeEdit();
		this.loadUnloadPanel.unloadGridEditPlugin.completeEdit();

		if (!this.generalPanel.getForm().isValid()) {
			this.tabPanel.setActiveTab( this.generalPanel );
			return false;
		}

		if (!this.clientPanel.getForm().isValid()) {
			this.tabPanel.setActiveTab( this.clientPanel );
			return false;
		}

		if (!this.loadUnloadPanel.getForm().isValid()) {
			this.tabPanel.setActiveTab( this.loadUnloadPanel );
			return false;
		}

		if (!this.ferryPanel.getForm().isValid()) {
			this.tabPanel.setActiveTab( this.ferryPanel );
			return false;
		}

		if (!this.docPanel.getForm().isValid()) {
			this.tabPanel.setActiveTab( this.docPanel );
			return false;
		}

		if (!this.finePanel.getForm().isValid()) {
			this.tabPanel.setActiveTab( this.finePanel );
			return false;
		}

		/*if (!this.surveerPanel.getForm().isValid()) {
			this.tabPanel.setActiveTab( this.surveerPanel );
			return false;
		}*/

		Ext.MessageBox.confirm('Сохранение', 'Вы уверены что хотите сохранить эту запись?',
			function (btn) {
				const mode = this.mode

				if (btn === 'yes') {
					const data = {};
					data.data = this.ownerModule.app.getFormValues(this.mainForm)

					const selectedDriver = this.ferryPanel.driverFioSelect ?? null
					if (selectedDriver) {
						if (selectedDriver?.value) {
							data.data.ferryfiodriver = selectedDriver?.rawValue ?? ''
						} else {
							// Водитель не выбран
							data.data.driver_id = null
							data.data.ferryfiodriver = ''
							data.data.ferryphone = ''
							data.data.ferrypassport = ''
						}
					}

					const selectedCar = this.ferryPanel.carLicensePlateNumberSelect ?? null
					if (selectedCar) {
						if (selectedCar?.value) {
							data.data.ferrycarnumber = selectedCar?.rawValue ?? ''
						} else {
							// Машина не выбрана
							data.data.ferrycar_id = null
							data.data.ferrycarnumber = ''
							data.data.ferrycar = ''
							data.data.ferrycarpp = ''
							data.data.ferrycarppnumber = ''
							data.data.ferryman_typets = ''
							data.data.ferryman_typets_str = ''
						}
					}

					data.data.multimodal_id = this.multimodal_id;
					data.origData = (this.data && this.data.data) && !['copy', 'multi'].includes(mode)
						? this.data.data
						: null

					data.loadGridDeleted = this.loadUnloadPanel.loadStoreDeleted
					data.unloadGridDeleted = this.loadUnloadPanel.unloadStoreDeleted

					data.loadGridOrig = this.data.loadGrid
					data.unloadGridOrig = this.data.unloadGrid

					data.loadGrid = []
					data.unloadGrid = []

					let rec = null
					for (let i = 0; i < this.loadUnloadPanel.loadStore.getCount(); i++) {
						rec = this.loadUnloadPanel.loadStore.getAt(i)
						data.loadGrid.push({
							id: rec.get('id'),
							extid: rec.get('extid'),
							date: rec.get('date'),
							time: rec.get('time'),
							date_fact: rec.get('date_fact'),
							time_fact: rec.get('time_fact'),
							comment: rec.get('comment'),
							address: rec.get('address'),
							contacts: rec.get('contacts'),
							dirty: (rec.dirty && (rec.dirty === true)) ? 1 : 0
						})
					}

					for (let i = 0; i < this.loadUnloadPanel.unloadStore.getCount(); i++) {
						rec = this.loadUnloadPanel.unloadStore.getAt(i);
						data.unloadGrid.push({
							id: rec.get('id'),
							extid: rec.get('extid'),
							date: rec.get('date'),
							time: rec.get('time'),
							date_fact: rec.get('date_fact'),
							time_fact: rec.get('time_fact'),
							comment: rec.get('comment'),
							address: rec.get('address'),
							contacts: rec.get('contacts'),
							dirty: (rec.dirty && (rec.dirty === true)) ? 1 : 0
						})
					}

					this.ownerModule.app.doAjax({
							module: this.ownerModule.moduleId,
							method: 'transpSave',
							// Если это копирование или доп. заявка, то устанавливаем режим 'new', иначе используем текущий режим
							mode: ['copy', 'multi'].includes(mode) ? 'new' : mode,
							// Если это новая запись, копия или доп. заявка, устанавливаем идентификатор 0, иначе используем текущий id сделки
							id: ['new', 'copy', 'multi'].includes(mode) ? 0 : this.oid,
							data: Ext.encode(data)
						},
						function (res) {
							const data = res?.data?.data ?? {}
							const tid = res?.id ?? null
							const isMultimodal = data?.multimodal === 1 // Допзаявка?
							const calcPanel = this.calcPanel
							if (typeof calcPanel?.calc === 'function') {
								calcPanel.calc() // Динамическое обновление вкладки расчеты при сохранении сделки
							}
							// При успешных запросах на создание/редактирование сделок - обновляем грид Грузоперевозки
/*							const gridPanel = Ext.getCmp('transportation_transportationsGridPanel');
							if (gridPanel) {
								gridPanel.store.load()
							}*/

							const clientRequestDate = data?.client_request_date ?? null
							if (clientRequestDate && typeof clientRequestDate === 'string') {
								//  Обрезаем только дату, оставляя '08.04.2025'
								data.client_request_date = clientRequestDate.split(' ')[0]
							}

							const tidString = tid.toString() // Преобразуем идентификатор сделки в строку
							const DealHelper = helpers.transportation

							// Обновляем tid и другие параметры после сохранения новой сделки/копии/доп-заявки
							if (tid && ['new', 'copy', 'multi'].includes(mode)) {
								DealHelper.changeNewTransportationTabId(tidString)

								this.financePanel.gridStore.proxy.extraParams.tid = tid
								this.docPanel.docGridStore.proxy.extraParams.tid = tid
								// this.surveerPanel.docGridStore.proxy.extraParams.tid = tid
								this.reportPanel.store.proxy.extraParams.tid = tid
							}

							if (res.data) {
								this.data = res.data
								// Загружаем данные в соответствующие панели
								this.loadData()
								// Если режим копирования или доп. заявки, заполняем форму клиента
								if (['copy', 'multi'].includes(mode)) {
									this.clientPanel.getForm().setValues(this.data.data)
								}

								// Обновляем идентификатор (tab.multiId в localStorage) для доп/заявок
								if (isMultimodal) {
									DealHelper.handleMultimodalTab(tidString, data)
								}

								// Получаем активную вкладку для дальнейших действий
								const activeTab = this.ownerModule.getActiveTab()
								// Если это новая сделка, копия или доп. заявка, обновляем вкладку
								if (['new', 'copy', 'multi'].includes(mode) && activeTab) {
									this.mode = 'edit' // Устанавливаем режим редактирования
									this.oid = tidString // Обновляем идентификатор объекта сделки
									activeTab.tid = tidString // Обновляем идентификатор сделки на вкладке

									// Генерируем строку для заголовка вкладки
									const idStr = DealHelper.getEditTabId(tid, data) ?? ''
									const title = `Грузоперевозка ${idStr}` // Формируем новый заголовок
									activeTab.setTitle(title) // Применяем новый заголовок для вкладки

									// Убираем вкладки c id = 'new', если таковые есть
									DealHelper.removeEmptyTabs()
								}
							}

							// Обновляем стор вкладки Лог
							const logPanel = this.tabPanel.getComponent('logPanel') ?? null
							if (!logPanel)
								return false
							logPanel.refreshLogsAfterSave(this.oid)
						}, this)
				}
			},
			this
		);
	},
	ferryNds: function() {
		var clientNds = this.clientPanel.clientNdsCmb.getValue();
		var ferryman = this.ferryPanel.ferrymanCmb.findRecordByValue( this.ferryPanel.ferrymanCmb.getValue() );

		if (ferryman) {
			if (clientNds == 0) {
				if (ferryman.get('nds') == 'WONDS')
					this.ferryPanel.ferryNdsCmb.setValue('WONDS');
				else
					this.ferryPanel.ferryNdsCmb.setValue('ZERONDS');
			}
			else if (clientNds == 20)
				this.ferryPanel.ferryNdsCmb.setValue(ferryman.get('nds'));
			else
				this.ferryPanel.ferryNdsCmb.setValue();
		}
		else
			this.ferryPanel.ferryNdsCmb.setValue();
	},
	showMask: function(msg) {
		this.body.mask(msg + '...', 'x-mask-loading');
	},
	hideMask: function() {
		this.body.unmask();
	}
});

Ext.define('kDesktop.transportation3.transpEdit.fromUnloadCheckWnd', {
	extend: 'Ext.window.Window',
	constructor: function(config) {
		config = config || {};

		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.data = config.data;
		this.oid = config.oid;
		this.mode = config.mode;
		this.clientConfig = helpers.types.isObjectAndHasProps(config?.clientConfig) ? config?.clientConfig : {}
		Ext.applyIf(config, {
			width: 920,
			height: 850,
			closable: false,
			collapsible: false,
			minimizable: false,
			preventHeader: true,
			onEsc: Ext.emptyFn,
			layout: 'fit',
			modal: true,
			border: false,
			items: [
				// TODO
				Ext.create('kDesktop.transportation3.transpEdit', {
					ownerModule: this.ownerModule,
					parent: this,
					oid: this.oid,
					data: this.data,
					mode: this.mode,
					fromUnloadCheckWnd: true,
					permissions: this.ownerModule.permissions,
					clientConfig: this.clientConfig,
				})
			]
		});

		kDesktop.transportation3.transpEdit.fromUnloadCheckWnd.superclass.constructor.call(this, config);
	}
});

Ext.define('kDesktop.transportation3.transpEdit.generalPanel', {
	extend: 'Ext.form.Panel',
	cls: 'fixed-width-panel',
	constructor: function(config) {
		config = config || {};

		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.priv = this.parent.priv;

		Ext.applyIf(config, {
			closable: false,
			autoScroll: true,
			frame: true,
			defaults: { xtype: 'container', layout: { type: 'hbox'} },
			items: [
				{
					items: [
						{xtype: 'displayfield', width: 150, value: 'Номер'},
						{
							xtype: 'textfield',
							width: 700,
							name: 'idstr',
							readOnly: true
						}
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 150, value: 'Статус'},
						{
							xtype: 'combobox',
							name: 'transp_status',
							ref: 'statusCmb',
							width: 250,
							queryMode: 'local',
							displayField: 'value',
							valueField: 'key',
							editable: false,
							store: Ext.create('Ext.data.JsonStore', {fields: ['key', 'value'], idProperty: 'key'})
						}
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 150, value: 'Дата создания'},
						{
							xtype: 'textfield',
							width: 130,
							name: 'date',
							readOnly: true
						}
					]
				},
				{
					xtype : 'container',
					layout: { type: 'hbox' },
					hidden: ((this.parent.mode == 'edit') && this.priv && this.priv.transportation && this.priv.transportation.modChangeOwner) ? false : true,
					items: [
						{xtype: 'displayfield', width: 150, value: 'Менеджер'},
						{
							xtype: 'combobox',
							width: 250,
							name: 'manager',
							ref: 'managerCmb',
							queryMode: 'local',
							displayField: 'value',
							valueField: 'key',
							editable: false,
							store: Ext.create('Ext.data.JsonStore', {fields: ['key', 'value'], idProperty: 'key'})
						}
					]
				},
				{
					xtype : 'container',
					layout: { type: 'hbox' },
					hidden: ((this.parent.mode == 'edit') && !(this.priv && this.priv.transportation && this.priv.transportation.modChangeOwner)) ? false : true,
					items: [
						{xtype: 'displayfield', width: 150, value: 'Менеджер'},
						{
							xtype: 'textfield',
							width: 250,
							name: 'manager_login',
							readOnly: true
						}
					]
				},
				{
					xtype : 'container',
					layout: {
						type: 'hbox'
					},
					items: [
						{xtype: 'displayfield', width: 150, value: 'Помощь логиста'},
						{
							xtype: 'combobox',
							name: 'logist',
							ref: 'logistCmb',
							width: 250,
							queryMode: 'local',
							displayField: 'value',
							valueField: 'key',
							editable: false,
							store: Ext.create('Ext.data.JsonStore', {fields: ['key', 'value'], idProperty: 'key'})
						}
					]
				},
				{
					xtype : 'container',
					layout: {
						type: 'hbox'
					},
					items: [
						{xtype: 'displayfield', width: 150, value: 'Примечание'},
						{
							xtype: 'textarea',
							name: 'comment',
							width: 700,
							height: 60,
						}
					]
				},
			]
		});

		kDesktop.transportation3.transpEdit.generalPanel.superclass.constructor.call(this, config);

 		this.ownerModule.app.createReference(this);
	}
});

Ext.define('kDesktop.transportation3.transpEdit.clientPanel', {
	extend: 'Ext.form.Panel',
	initComponent: function() {
		this.callParent(arguments)

		// При смене контракта - обновляем валюту и курс
		this.on('onSelectContract', this.onSelectContract, this)
	},
	onSelectContract(combo, record) {
		const contract = record[0] ?? record
		const cur = contract.get('currency')
		const clientCurrencyFld = this.down('#clientCurrencyFld')
		if (clientCurrencyFld) {
			clientCurrencyFld.setValue(cur)
		}

		const clientCurrencyRateFld = this.down('#clientCurrencyRateFld')
		const rate = cur === 'RUR' ? '1' : contract.get('rate')
		if (clientCurrencyRateFld) {
			clientCurrencyRateFld.setValue(rate)
		}
	},
	constructor: function(config) {
		config = config || {};
		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.priv = this.parent.priv;
		this.permissions = config?.permissions ?? {}

		const RolesHelper = helpers.roles
		const canSeeClientName = !RolesHelper.isFieldHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS, 'client_name')
		if (!canSeeClientName) {
			this.clientStore = Ext.create('Ext.data.Store', {
				fields: ['id', 'name'],
				data: []
			})
		} else {
			this.clientStore = Ext.create('Ext.data.Store', {
				autoLoad: false,
				fields: ['id', 'name'],
				proxy: {
					actionMethods: 'POST',
					type: 'ajax',
					url: 'index.php',
					extraParams: {
						module: 'statistics',
						method: 'getClientList',
						limit: 50,
					},
					reader: {
						type: 'json',
						root: 'items',
						totalProperty: 'totalCount'
					}
				}
			})
		}

		const clientRequestFieldsFiltered = RolesHelper.filterFormFields([
			{
				xtype: 'textfield',
				name: 'client_request_no',
				alias: 'client_request',
				fieldLabel: '№ заявки клиент',
				labelSeparator: '',
				labelWidth: 165,
				width: 420,
			},
			{
				xtype: 'datefield',
				name: 'client_request_date',
				alias: 'client_request',
				width: 100,
				allowBlank: true,
				format: 'd.m.Y',
				submitFormat: 'd.m.Y H:i:s',
				editable: true,
				startDay: 1
			},
			{xtype: 'displayfield', width: 50, value: '', alias: 'client_request',},
		], this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS)

		const handleBlurDowntimeNumberValue = (field) => {
			const newValue = field.getValue()
			if (newValue === null || newValue === '') {
				field.setValue(0)
			}
		}
		const handleChangeDowntimeNumberValue = (field, newValue) => {
			if (newValue !== null && newValue !== '' && !isNaN(newValue)) {
				field.setValue(parseFloat(newValue))
			}
		}

		const data = this.parent?.data?.data ?? {}
		const clientContract = {
			currency: data?.client_currency ?? '',
			payType: data?.client_contract_pay_type ?? '',
		}

		const clientContractCurrencyString =
			helpers.transportationContract.getTransportationContractorCurrency(clientContract.payType, clientContract.currency, true)

		const clientContractCombobox = Ext.create('contractEnhancedCombobox', {
			name: 'clientcontract',
			ref: 'clientContractCmb',
			actionName: 'clientContractStore',
			contractorId: this.parent.data.data.client,
			tid: this.parent.oid,
			initialValue: this.parent?.data?.data?.clientcontract ?? null,
			width: 450,
			labelWidth: 0,
			labelSeparator: '',
			listeners: {
				onContractStoreLoaded: {
					fn: function () {
						this.procContractLimit()
					},
					scope: this
				},
			}
		})

		const clientCombobox = Ext.create('form.comboboxWithSearch', {
			ref: 'clientCmb',
			name: 'client',
			store: this.clientStore,
			fieldLabel: 'Клиент',
			alias: 'client_name',
			labelSeparator: '',
			width: 420,
			labelWidth: 170,
			minChars: 3,
			selectHandler: (cmb) => {
				// Сбрасываем Договор и Контактное лицо при смене Клиента
				const selectedClientId = cmb.getValue()
				const contractorId = selectedClientId ? parseInt(selectedClientId) : null
				const needToLoadStore = false

				const clientContractCombobox = this.down('contractenhancedcombobox')
				if (clientContractCombobox) {
					clientContractCombobox.fireEvent('resetAndUpdateStore', {
						contractorId,
						needToLoadStore,
					})
				}

				const clientPersonCombobox = this.down('personenhancedcombobox')
				if (clientPersonCombobox) {
					clientPersonCombobox.fireEvent('resetAndUpdateStore', {
						contractorId,
						needToLoadStore,
					})
				}
			}
		})

		const clientPersonCombobox = Ext.create('personEnhancedCombobox', {
			initialValue: this.parent?.data?.data?.clientperson ?? null,
			contractorId: this.parent.data.data.client,
			actionName: 'clientPersonStore',
			tid: this.parent.oid,
			name: 'clientperson',
			fieldLabel: 'Контактное лицо',
		})

		Ext.applyIf(config, {
			closable: false,
			autoScroll: true,
			frame: true,
			defaults: { xtype: 'container', layout: { type: 'hbox'} },
			items: [
				...RolesHelper.filterFormFields([
					{
						items: [
							clientCombobox,
							clientContractCombobox
						]
					},
					{
						xtype: 'container',
						layout: { type: 'hbox'},
                        items: [
							clientPersonCombobox
						]
					},
				], this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS),
				{
					items: [
						...clientRequestFieldsFiltered,
						{
							xtype: 'displayfield',
							width: helpers.types.isArrayWithLength(clientRequestFieldsFiltered) ? 100 : 170,
							value: '№ ТН клиент'
						},
						{
							xtype: 'textfield',
							name: 'client_tnnum',
							width: 200
						}
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 65, value: 'Тип услуг'},
						{
							xtype: 'combobox',
							name: 'typets',
							ref: 'typetsCmb',
							width: 140,
							queryMode: 'local',
							displayField: 'value',
							valueField: 'key',
							editable: true,
							store: Ext.create('Ext.data.JsonStore', {fields: ['key', 'value'], idProperty: 'key'}),
							listeners: {
								beforequery: function(queryEvent) {
									const combo = queryEvent.combo
									const store = combo.getStore()
									const queryString = queryEvent.query.toLowerCase()

									store.filterBy(function(record) {
										const value = record.get(combo.displayField).toLowerCase()
										return value.indexOf(queryString) !== -1
									})

									queryEvent.cancel = true
									combo.expand()
								}
							}
						},
						{
							xtype: 'textfield',
							name: 'typets_desc',
							width: 665,
							height: 22
						}
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 170, value: 'Маршрут (откуда/куда)'},
						{
							xtype: 'component',
							html: '<div class="copy-btn icon-copy"></div>',
							listeners: {
								afterrender: (component) => {
									component.getEl().on('click', () => {
										const clientFromPlace = this.parent.data?.data?.clientfromplace ?? ''
										const clientToPlace = this.parent.data?.data?.clienttoplace ?? ''
										const data = `${clientFromPlace} - ${clientToPlace}`

										if (navigator?.clipboard && navigator.clipboard?.writeText) {
											navigator.clipboard.writeText(data).then(() => console.log('Text copied to clipboard successfully'))
												.catch((err) => console.error(`Error copying text: ${err}`))
										} else {
											const tempInput = document.createElement('textarea')
											tempInput.style.position = 'absolute'
											tempInput.style.left = '-9999px'
											tempInput.value = data
											document.body.appendChild(tempInput)
											tempInput.select()
											try {
												document.execCommand('copy')
											} catch (err) {
												console.error(`Error copying text: ${err}`)
											}

											document.body.removeChild(tempInput)
										}
									})
								}
							},
							width: 22,
							height: 22,
							cls: 'copy-btn-wrapper',
						},
						{
							xtype: 'textfield',
							name: 'clientfromplace',
							width: 334,
							enableKeyEvents: true,
							listeners: {
								'keyup': {
									fn: function(o){
										this.parent.ferryPanel.ferryFromPlaceFld.setValue(o.getValue());
									},
									scope: this
								}
							}
						},
						{
							xtype: 'textfield',
							name: 'clienttoplace',
							width: 339,
							enableKeyEvents: true,
							listeners: {
								'keyup': {
									fn: function(o){
										this.parent.ferryPanel.ferryToPlaceFld.setValue(o.getValue());
									},
									scope: this
								}
							}
						}
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 170, value: 'Направление'},
						{
							xtype: 'combobox',
							name: 'region',
							ref: 'regionCmb',
							width: 250,
							queryMode: 'local',
							displayField: 'value',
							valueField: 'key',
							editable: false,
							allowBlank: false,
							store: Ext.create('Ext.data.JsonStore', {fields: ['key', 'value'], idProperty: 'key'})
						}
					]
				},
				...RolesHelper.filterFormFields([
						{
							items: [
								{
									xtype: 'textfield',
									name: 'cargo',
									alias: 'description',
									fieldLabel: 'Характер груза',
									labelSeparator: '',
									labelWidth: 165,
									width: 870
								}
							]
						},
					{
						items: [
							{
								xtype: 'numberfield',
								name: 'cargotemp1',
								labelWidth: 165,
								fieldLabel: 'Температурный режим',
								labelSeparator: '',
								width: 240,
								minValue: -273,
								decimalPrecision: 0,
								hideTrigger:true
							},
							{
								xtype: 'numberfield',
								name: 'cargotemp2',
								width: 70,
								minValue: -273,
								decimalPrecision: 0,
								hideTrigger:true
							},
							{xtype: 'displayfield', width: 50, value: '', alias: 'cargotemp2'},
							{
								xtype: 'numberfield',
								name: 'cargoplaces',
								fieldLabel: 'Количество погрузочных мест',
								labelSeparator: '',
								labelWidth: 180,
								width: 240,
								minValue: 0,
								decimalPrecision: 0,
								hideTrigger: true,
								style: {
									marginRight: '10px'
								}
							},
							{
								xtype: 'checkbox',
								name: 'cargoplacesttn',
								width: 20
							},
							{
								xtype: 'displayfield', width: 50, value: 'по ТТН', alias: 'cargoplacesttn', style: {
									marginRight: '50px'
								}
							},
							{
								xtype: 'numberfield',
								name: 'cargovolume',
								width: 140,
								fieldLabel: 'Объем (м3)',
								labelSeparator: '',
								labelWidth: 70,
								minValue: 0,
								decimalPrecision: 2,
								hideTrigger:true
							}
						]
					},
					{
						items: [
							{
								xtype: 'numberfield',
								name: 'cargoweight',
								width: 240,
								fieldLabel: 'Вес (т)',
								labelSeparator: '',
								labelWidth: 165,
								minValue: 0,
								decimalPrecision: 2,
								hideTrigger:true
							},
							{
								xtype: 'combobox',
								name: 'cargoweighttype',
								width: 70,
								queryMode: 'local',
								displayField: 'name',
								valueField: 'id',
								store: Ext.create('Ext.data.ArrayStore', {
									fields: [
										'id',
										'name'
									],
									data: [
										['0', 'нетто'],
										['1', 'брутто']
									]
								}),
								style: {
									marginRight: '50px'
								}
							},
							{
								xtype: 'textfield',
								name: 'cargoprofile',
								width: 510,
								fieldLabel: 'Габариты',
								labelSeparator: '',
								labelWidth: 180,
							}
						]
					},
					{
						items: [
							{
								xtype: 'textarea',
								name: 'cargoother',
								fieldLabel: 'Иное',
								labelSeparator: '',
								labelWidth: 165,
								width: 870,
								height: 30
							}
						]
					},
					], this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS
				),
				{
					items: [
						{xtype: 'displayfield', width: 170, value: 'Тип загрузки'},
						{
							xtype: 'textfield',
							name: 'cargoloadtype',
							width: 270
						},
						{xtype: 'displayfield', width: 70, value: ''},
						{xtype: 'displayfield', width: 90, value: 'Тип выгрузки'},
						{
							xtype: 'textfield',
							name: 'cargounloadtype',
							width: 270
						}
					]
				},
				{
					height: 10
				},
				{
					items: [
						{
							xtype: 'combobox',
							name: 'clientnds',
							ref: 'clientNdsCmb',
							width: 170,
							queryMode: 'local',
							displayField: 'name',
							valueField: 'id',
							editable: false,
							store: Ext.create('Ext.data.Store', {
								fields: ['id', 'name'],
								data: this.ownerModule?.data?.clientVatOptions ?? []
							}),
							/*listeners: {
								select: () => this.parent.ferryNds()
							}*/
						}
					]
				},
				{
					xtype : 'container',
					layout: {
						type: 'hbox'
					},
					items: [
						{
							xtype : 'checkbox',
							name: 'cargoinsurance',
							width: 20
						},
						{xtype: 'displayfield', width: 150, value: 'Страховка'},
						...RolesHelper.filterFormFields([
							{
								xtype: 'numberfield',
								name: 'cargoprice',
								fieldLabel: 'Стоимость груза',
								labelSeparator: '',
								labelWidth: 110,
								width: 210,
								minValue: 0,
								decimalPrecision: 2,
								hideTrigger:true,
								allowBlank: false,
								validator: function(v) {
									if (v > 0)
										return true;
									else
										return "Значение должно быть больше 0";
								}
							},
							{
								xtype: 'combobox',
								name: 'cargopricecurrency',
								alias: 'cargoprice',
								width: 60,
								queryMode: 'local',
								displayField: 'name',
								valueField: 'id',
								allowBlank: false,
								editable: false,
								store: Ext.create('Ext.data.ArrayStore', {
									fields: [
										'id',
										'name'
									],
									data: [
										['USD', 'USD'],
										['EUR', 'EUR'],
										['KZT', 'KZT'],
										['RUR', 'RUR'],
										['CNY', 'CNY'],
										['UZS', 'UZS']
									]
								})
							},
						], this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS),
						{xtype: 'displayfield', width: 20, value: ''},
						{xtype: 'displayfield', width: 220, value: 'Стоимость страховки'},
						{
							xtype: 'numberfield',
							name: 'cargoinsuranceusvalue',
							width: 100,
							minValue: 0,
							decimalPrecision: 2,
							hideTrigger:true
						}
					]
				},
				{
					xtype : 'container',
					layout: {
						type: 'hbox'
					},
					items: [
						{xtype: 'displayfield', width: 460, value: ''},
						{xtype: 'displayfield', width: 220, value: 'Стоимость страховки для клиента'},
						{
							xtype: 'numberfield',
							name: 'cargoinsuranceclientvalue',
							width: 100,
							minValue: 0,
							decimalPrecision: 2,
							hideTrigger:true
						}
					]
				},
				...RolesHelper.filterFormFields([
					{
						xtype : 'container',
						layout: {
							type: 'hbox'
						},
						items: [
							{
								xtype: 'numberfield',
								name: 'clientrefund',
								fieldLabel: '$',
								labelSeparator: '',
								labelWidth: 165,
								width: 290,
								minValue: 0,
								decimalPrecision: 2,
								hideTrigger: true,
								style: {
									marginRight: '170px'
								}
							},
							{
								xtype: 'textfield',
								name: 'cargoinsurance_num',
								width: 410,
								fieldLabel: 'Номер страхового полиса',
								labelSeparator: '',
								labelWidth: 220,
							}
						]
					},
					{
						xtype : 'container',
						layout: {
							type: 'hbox'
						},
						items: [
							{
								xtype: 'numberfield',
								name: 'clientothercharges',
								width: 290,
								fieldLabel: 'Прочие расходы',
								labelSeparator: '',
								labelWidth: 165,
								minValue: 0,
								decimalPrecision: 2,
								hideTrigger: true,
							},
							{
								xtype: 'displayfield',
								width: 135,
								value: clientContractCurrencyString,
								style: {
									marginLeft: '8px',
									marginRight: '27px',
								}
							},
							{
								xtype: 'textfield',
								name: 'clientotherchargestarget',
								width: 410,
								fieldLabel: 'Цель',
								labelSeparator: '',
								labelWidth: 100,
							},
						]
					},
					{
						xtype : 'container',
						layout: {
							type: 'hbox'
						},
						items: [
							{
								xtype: 'numberfield',
								name: 'clientpricenal',
								width: 290,
								fieldLabel: 'Стоимость нал',
								labelSeparator: '',
								labelWidth: 165,
								minValue: 0,
								decimalPrecision: 2,
								hideTrigger:true,
								enableKeyEvents: true,
								style: {
									marginRight: '40px'
								}
							},
							{
								xtype: 'numberfield',
								name: 'clientpricedeposit',
								width: 560,
								fieldLabel: 'Залог',
								labelSeparator: '',
								labelWidth: 100,
								minValue: 0,
								decimalPrecision: 2,
								hideTrigger: true,
								style: {
									marginRight: '40px'
								}
							}
						]
					},
					{
						xtype : 'container',
						layout: {
							type: 'hbox'
						},
						items: [
							{
								xtype: 'textfield',
								name: 'client_currency',
								ref: 'clientCurrencyFld',
								itemId: 'clientCurrencyFld',
								width: 230,
								fieldLabel: 'Валюта',
								labelSeparator: '',
								labelWidth: 165,
								allowBlank: false,
								readOnly: true
							},
							{
								xtype: 'numberfield',
								name: 'client_currency_sum',
								ref: 'clientCurrencySumFld',
								width: 150,
								value: '0',
								minValue: 0,
								decimalPrecision: 2,
								hideTrigger: true,
								listeners: {
									change: {
										fn: this.currencyValue,
										scope: this
									}
								},
								style: {
									marginRight: '30px'
								}
							},
							{
								xtype: 'numberfield',
								name: 'client_currency_rate',
								ref: 'clientCurrencyRateFld',
								itemId: 'clientCurrencyRateFld',
								width: 200,
								fieldLabel: 'Курс план',
								labelWidth: 70,
								labelSeparator: '',
								value: '0',
								minValue: 0,
								decimalPrecision: 6,
								hideTrigger: true,
								listeners: {
									change: {
										fn: this.currencyValue,
										scope: this
									}
								},
								style: {
									marginRight: '70px'
								}
							},
							{
								xtype: 'numberfield',
								name: 'client_currency_total',
								ref: 'clientCurrencyTotalFld',
								width: 190,
								fieldLabel: 'Итого',
								labelSeparator: '',
								labelWidth: 40,
								value: '0',
								minValue: 0,
								decimalPrecision: 4,
								hideTrigger: true
							}
						]
					},
					{
						xtype: 'container',
						layout: {
							type: 'hbox'
						},
						items: [
							{xtype: 'displayfield', width: 170, value: 'Простой'},
							{
								xtype: 'combobox',
								name: 'client_downtime_currency',
								width: 80,
								queryMode: 'local',
								displayField: 'name',
								valueField: 'id',
								editable: false,
								store: Ext.create('Ext.data.ArrayStore', {
									fields: [
										'id',
										'name'
									],
									data: [
										['USD', 'USD'],
										['EUR', 'EUR'],
										['KZT', 'KZT'],
										['RUR', 'RUR'],
										['CNY', 'CNY'],
										['UZS', 'UZS']
									]
								}),
								style: {
									marginRight: '30px'
								},
							},
							{xtype: 'displayfield', width: 50, value: 'Ед.изм'},
							{
								xtype: 'combobox',
								name: 'client_downtime_unit',
								width: 80,
								queryMode: 'local',
								displayField: 'name',
								valueField: 'id',
								editable: false,
								store: Ext.create('Ext.data.ArrayStore', {
									fields: [
										'id',
										'name'
									],
									data: [
										['day', 'Сутки'],
										['workday', 'Р.День'],
										['hour', 'Час']
									]
								}),
								style: {
									marginRight: '30px'
								},
							},
							{xtype: 'displayfield', width: 50, value: 'Кол-во'},
							{
								xtype: 'numberfield',
								name: 'client_downtime_value',
								width: 60,
								value: '0',
								minValue: 0,
								decimalPrecision: 0,
								hideTrigger: true,
								listeners: {
									blur: handleBlurDowntimeNumberValue,
									change: handleChangeDowntimeNumberValue,
								},
								style: {
									marginRight: '30px'
								},
							},
							{xtype: 'displayfield', width: 50, value: 'Сумма'},
							{
								xtype: 'numberfield',
								name: 'client_downtime_sum',
								width: 90,
								value: '0',
								minValue: 0,
								decimalPrecision: 4,
								hideTrigger: true,
								listeners: {
									blur: handleBlurDowntimeNumberValue,
									change: handleChangeDowntimeNumberValue,
								},
							}
						]
					},
					{
						xtype : 'container',
						layout: {
							type: 'hbox'
						},
						items: [
							{
								xtype: 'displayfield',
								alias: 'clientpaycomment',
								width: 170,
								value: 'Примечания к оплате<br />Особые инструкции'
							},
							{
								xtype: 'textarea',
								name: 'clientpaycomment',
								width: 700,
								height: 70
							}
						]
					},
					{
						xtype : 'container',
						layout: {
							type: 'hbox'
						},
						items: [
							{
								xtype: 'datefield',
								name: 'clientinvoicedate',
								alias: 'clientinvoicedate_str',
								fieldLabel: 'Дата и номер счета',
								labelSeparator: '',
								labelWidth: 165,
								width: 270,
								allowBlank: true,
								format: 'd.m.Y',
								editable: true,
								startDay: 1
							},
							{
								xtype: 'textfield',
								alias: 'clientinvoicedate_str',
								name: 'clientinvoice',
								width: 300
							},
							{
								xtype: 'textfield',
								alias: 'clientinvoicedate_str',
								name: 'clientinvoice2',
								width: 300,
								readOnly: true
							}
						]
					},
					{
						xtype : 'container',
						layout: {
							type: 'hbox'
						},
						items: [
							{
								xtype: 'datefield',
								name: 'clientinvoice_actdate',
								alias: 'clientinvoiceactdate_str',
								width: 270,
								fieldLabel: 'Дата и номер акта',
								labelSeparator: '',
								labelWidth: 165,
								allowBlank: true,
								format: 'd.m.Y',
								editable: true,
								startDay: 1
							},
							{
								xtype: 'textfield',
								name: 'clientinvoice_act',
								alias: 'clientinvoiceactdate_str',
								width: 600
							}
						]
					},
					{
						xtype : 'container',
						layout: {
							type: 'hbox'
						},
						items: [
							{
								xtype: 'datefield',
								name: 'clientinvoice_scfdate',
								alias: 'clientinvoice_scf',
								width: 270,
								fieldLabel: 'Дата и номер счф',
								labelSeparator: '',
								labelWidth: 165,
								allowBlank: true,
								format: 'd.m.Y',
								editable: true,
								startDay: 1
							},
							{
								xtype: 'textfield',
								name: 'clientinvoice_scf',
								width: 600
							}
						]
					},
					{
						xtype: 'datefield',
						labelSeparator: '',
						name: 'client_plandate',
						alias: 'client_plandate_str',
						fieldLabel: 'Плановая дата оплаты',
						labelWidth: 165,
						width: 270,
						allowBlank: true,
						format: 'd.m.Y',
						editable: false,
						startDay: 1
					}
				], this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS),
				{
					xtype : 'container',
					layout: {
						type: 'hbox'
					},
					items: [
						{xtype: 'displayfield', width: 650, height: 120, ref: 'clientSnsFld'},
						{xtype: 'displayfield', width: 230, ref: 'clientAccountantFld'}
					]
				}
			]
		});

		kDesktop.transportation3.transpEdit.clientPanel.superclass.constructor.call(this, config);
 		this.ownerModule.app.createReference(this);
	},
	currencyValue: function () {
		// Если курс валюты KZT - то в курсе * 100, и в ИТОГО соответственно делим на 100
		const isKZT = this.clientCurrencyFld.getValue() === helpers.currencies.KZT
		if (this.clientCurrencyRateFld && isKZT) {
			const currentRateValue = this.clientCurrencyRateFld.getValue()
			if (currentRateValue && currentRateValue < 1) {
				const newRate = (currentRateValue * 100).toFixed(4)
				this.clientCurrencyRateFld.setValue(newRate)
			}
		}

		if (!this.clientCurrencyRateFld || !this.clientCurrencySumFld || !this.clientCurrencyTotalFld) return false
		const product = this.clientCurrencyRateFld.getValue() * this.clientCurrencySumFld.getValue()
		const value = !isKZT ? product : product / 100
		this.clientCurrencyTotalFld.setValue(value)
	},
	procContractLimit: function() {
		if (this.clientContractCmb) {
			this.clientContractCmb.removeCls('combobox-bold-red');

			var rec = this.clientContractCmb.getValue();
			if (rec && (rec > 0)) rec = this.clientContractCmb.store.findRecord('id', rec);
			if (rec && (rec.get('sl') > 90)) this.clientContractCmb.addCls('combobox-bold-red');
		}
	},
})

Ext.define('kDesktop.transportation3.transpEdit.loadUnloadPanel', {
	extend: 'Ext.form.Panel',
	alias: 'widget.loadUnloadPanel',
	constructor: function(config) {
		config = config || {};

		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.priv = this.parent.priv;
		const newDealData = config?.newDealData ?? {}

		this.loadStoreDeleted = [];
		this.loadStore = Ext.create('Ext.data.ArrayStore', {
			fields: [
				{name: 'id', type: 'int'},
				{name: 'extid', type: 'int'},
				{name: 'date', type: 'date'},
				{name: 'time', type: 'date'},
				{name: 'date_fact', type: 'date'},
				{name: 'time_fact', type: 'date'},
				{name: 'loading_date_time', type: 'date'},
				{name: 'loading_date_time_fact', type: 'date'},
				'plan_fact_date_diff',
				'comment',
				'address',
				'contacts',
			]
		})

		this.loadGridEditPlugin = Ext.create('Ext.grid.plugin.CellEditing', {
			clicksToEdit: 1,
		})

		this.loadGrid = Ext.create('Ext.grid.Panel', {
			store: this.loadStore,
			height: 290,
			loadMask: true,
			columnLines: true,
			columns: [
				{
					header: 'План Загрузки, время',
					editor: false,
					dataIndex: 'loading_date_time',
					width: 200,
					renderer: (value, el, record) =>
						this.renderDateTime(value, record, 'loading_date_time')
				},
				{
					header: 'Факт Загрузки, время',
					editor: false,
					dataIndex: 'loading_date_time_fact',
					width: 200,
					renderer: (value, el, record) =>
						this.renderDateTime(value, record, 'loading_date_time_fact')
				},
				{
					header: "План/факт",
					editor: false,
					dataIndex: 'plan_fact_date_diff',
					width: 115,
					sortable: false,
					renderer: (value) => {
						const diff = value?.diff ?? null
						if (!diff)
							return ''

						// TODO возможно надо брать из конфига - уточнить
						// const GridHelper = helpers.transportationGrid
						// const gridPalette = common.currentUser.getConfig()?.gridCellColorsPalette ?? {}
						if (value?.seconds === 0) {
							return diff
						}
						const color = value.overdue ? 'red' : 'green'
							// ? `${gridPalette[GridHelper.WARN_GRID_COLOR_KEY]}` ?? 'red'
							// : `${gridPalette[GridHelper.SUCCESS_GRID_COLOR_KEY]}` ?? 'green'
						return `<span style="color: ${color}">${diff}</span>`
					}
				},
				{
					header: "Грузоотправитель",
					dataIndex: 'comment',
					width: 280,
					sortable: true,
					field: {
						allowBlank: true
					}
				},
				{
					header: "Адрес",
					dataIndex: 'address',
					width: 380,
					sortable: true,
					field: {
						allowBlank: true
					}
				},
				{
					header: "Контактное лицо",
					dataIndex: 'contacts',
					width: 380,
					sortable: true,
					field: {
						allowBlank: true
					}
				}
			],
			viewConfig: {
				stripeRows: true
			},
			plugins: [ this.loadGridEditPlugin ],
			selModel: {
				selType: 'cellmodel'
			}
		})

		this.createDateTimeCellClickHandler(this.loadGrid, {
			loading_date_time: 'План дата и время Загрузки',
			loading_date_time_fact: 'Факт дата и время Загрузки'
		})

		this.loadGrid.on('containercontextmenu', function(view, eventObj){
			var _contextMenu = Ext.create('Ext.menu.Menu', {
				items: [
					{
						text: 'Добавить',
						scope: this,
						handler: function (){
							this.addLoadUnload(this.loadStore, 'load');
						}
					}
				]
			});
			_contextMenu.showAt(eventObj.getXY());
			eventObj.stopEvent();
		}, this);

		this.loadGrid.on('itemcontextmenu',function(view, rec, node, index, eventObj) {
			var _contextMenu = Ext.create('Ext.menu.Menu', {
				items: [
					{
						text: 'Добавить',
						scope: this,
						handler: function (){
							this.addLoadUnload(this.loadStore, 'load');
						}
					},
					{
						text: 'Избранные',
						scope: this,
						handler: function (){
							Ext.create('kDesktop.transportation3.transpEdit.loadUnloadPanel.favoriteWnd', { ownerModule: this.ownerModule, parent: this, data: rec}).show();
						}
					},
					{
						text: 'Копировать адрес',
						scope: this,
						handler: function (){
							this.ownerModule.app.copyToClipboard(rec.get('address') + ' ' + rec.get('contacts'));
						}
					},
					{
						text: 'Удалить',
						iconCls: 'del-icon',
						scope: this,
						handler: function (){
							Ext.Msg.show({title: 'Удалить?', msg: 'Удалить эту запись?',
								buttons: Ext.Msg.YESNO,
								icon: Ext.Msg.QUESTION,
								buttonText: {
									yes: "Да",
									no: "Нет"
								},
								callback: function(btn){
									if(btn == 'yes') {
										this.loadStore.removeAt(index);
										if (rec.get('extid')) {
											this.loadStoreDeleted.push({
												id: rec.get('extid')
											});
										}
									}
								},
								scope: this
							});
						}
					}
				]
			});
			_contextMenu.showAt(eventObj.getXY());
			eventObj.stopEvent();
		}, this);
		this.attachTooltipToGrid(this.loadGrid)

		this.unloadStoreDeleted = [];
		this.unloadStore = Ext.create('Ext.data.ArrayStore', {
			fields: [
				{name: 'id', type: 'int'},
				{name: 'extid', type: 'int'},
				{name: 'date', type: 'date'},
				{name: 'time', type: 'date'},
				{name: 'date_fact', type: 'date'},
				{name: 'time_fact', type: 'date'},
				{name: 'offloading_date_time', type: 'date'},
				{name: 'offloading_date_time_fact', type: 'date'},
				'plan_fact_date_diff',
				'comment',
				'address',
				'contacts'
			]
		});

		this.unloadGridEditPlugin = Ext.create('Ext.grid.plugin.CellEditing', {
			clicksToEdit: 1,
		})

		this.addLoadUnloadFromData(newDealData)

		this.unloadGrid = Ext.create('Ext.grid.Panel', {
			store: this.unloadStore,
			height: 290,
			loadMask: true,
			columnLines: true,
			columns: [
				{
					header: 'План Выгрузки, время',
					editor: false,
					dataIndex: 'offloading_date_time',
					width: 200,
					renderer: (value, el, record) =>
						this.renderDateTime(value, record, 'offloading_date_time')
				},
				{
					header: 'Факт Выгрузки, время',
					editor: false,
					dataIndex: 'offloading_date_time_fact',
					width: 200,
					renderer: (value, el, record) =>
						this.renderDateTime(value, record, 'offloading_date_time_fact')
				},
				{
					header: "План/факт",
					editor: false,
					dataIndex: 'plan_fact_date_diff',
					width: 115,
					sortable: false,
					renderer: (value) => {
						const diff = value?.diff ?? null
						if (!diff)
							return ''

						if (value?.seconds === 0) {
							return diff
						}

						return `<span style="color: ${value.overdue ? 'red' : 'green'}">${diff}</span>`
					}
				},
				{
					header: "Грузополучатель",
					dataIndex: 'comment',
					width: 280,
					sortable: true,
					field: {
						allowBlank: true
					}
				},
				{
					header: "Адрес",
					dataIndex: 'address',
					width: 380,
					sortable: true,
					field: {
						allowBlank: true
					}
				},
				{
					header: "Контактное лицо",
					dataIndex: 'contacts',
					width: 380,
					sortable: true,
					field: {
						allowBlank: true
					}
				}
			],
			viewConfig: {
				stripeRows: true
			},
			plugins: [this.unloadGridEditPlugin],
			selModel: {
				selType: 'cellmodel'
			}
		});

		this.createDateTimeCellClickHandler(this.unloadGrid, {
			offloading_date_time: 'План дата и время Выгрузки',
			offloading_date_time_fact: 'Факт дата и время Выгрузки'
		})

		this.unloadGrid.on('containercontextmenu', function(view, eventObj){
			var _contextMenu = Ext.create('Ext.menu.Menu', {
				items: [
					{
						text: 'Добавить',
						scope: this,
						handler: function (){
							this.addLoadUnload(this.unloadStore, 'offload');
						}
					}
				]
			});
			_contextMenu.showAt(eventObj.getXY());
			eventObj.stopEvent();
		}, this)

		this.unloadGrid.on('itemcontextmenu',function(view, rec, node, index, eventObj) {
			var _contextMenu = Ext.create('Ext.menu.Menu', {
				items: [
					{
						text: 'Добавить',
						scope: this,
						handler: function (){
							this.addLoadUnload(this.unloadStore, 'offload');
						}
					},
					{
						text: 'Избранные',
						scope: this,
						handler: function (){
							Ext.create('kDesktop.transportation3.transpEdit.loadUnloadPanel.favoriteWnd', { ownerModule: this.ownerModule, parent: this, data: rec}).show();
						}
					},
					{
						text: 'Копировать адрес',
						scope: this,
						handler: function (){
							this.ownerModule.app.copyToClipboard(rec.get('address') + ' ' + rec.get('contacts'));
						}
					},
					{
						text: 'Удалить',
						iconCls: 'del-icon',
						scope: this,
						handler: function (){
							Ext.Msg.show({title: 'Удалить?', msg: 'Удалить эту запись?',
								buttons: Ext.Msg.YESNO,
								icon: Ext.Msg.QUESTION,
								buttonText: {
									yes: "Да",
									no: "Нет"
								},
								callback: function(btn){
									if(btn == 'yes') {
										this.unloadStore.removeAt(index);
										if (rec.get('extid')) {
											this.unloadStoreDeleted.push({
												id: rec.get('extid')
											});
										}
									}
								},
								scope: this
							});
						}
					}
				]
			});
			_contextMenu.showAt(eventObj.getXY());
			eventObj.stopEvent();
		}, this);
		this.attachTooltipToGrid(this.unloadGrid)

		Ext.applyIf(config, {
			closable: false,
			autoScroll: true,
			frame: true,
			defaults: { xtype: 'container', layout: { type: 'hbox'} },
			items: [
				this.loadGrid,
				{xtype: 'container', height: 10},
 				this.unloadGrid,
				{xtype: 'container', height: 10},
				{
					items: [
						{xtype: 'displayfield', width: 170, value: 'Дата прибытия на границу'},
						{
							xtype: 'datefield',
							name: 'bordercross_date',
							width: 100,
							allowBlank: true,
							format: 'd.m.Y',
							editable: false,
							startDay: 1
						}
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 170, value: 'Дата выхода с границы'},
						{
							xtype: 'datefield',
							name: 'borderexit_date',
							width: 100,
							allowBlank: true,
							format: 'd.m.Y',
							editable: false,
							startDay: 1
						}
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 170, value: 'Погран переход'},
						{
							xtype: 'textfield',
							name: 'bordercross',
							width: 700
						}
					]
				},
				{xtype: 'container', height: 10},
				{
					items: [
						{
							xtype: 'displayfield',
							width: 170,
							value: 'Выставлять счёт с разбивкой',
						},
						{
							xtype: 'checkbox',
							name: 'split_invoice',
							width: 20,
							height: 21,
							itemId: 'splitInvoiceCheckbox',
							style: {
								paddingTop: '0px'
							},
							margin: '0 10 0 0',
							inputValue: true,
							uncheckedValue: false
						},
						{
							xtype: 'displayfield',
							value: 'Пробег по инотерритории:',
							width: 160
						},
						{
							xtype: 'numberfield',
							name: 'run_foreign',
							minValue: 0,
							width: 80,
							allowDecimals: false,
							listeners: {
								change: this.updateSplitInvoiceCheckbox,
								afterrender: this.updateSplitInvoiceCheckbox
							}
						},
						{
							xtype: 'displayfield',
							value: 'Пробег по территории РФ:',
							style: { marginTop: '3px' },
							width: 160,
							margin: '0 0 0 10'
						},
						{
							xtype: 'numberfield',
							name: 'run_rus',
							minValue: 0,
							width: 80,
							allowDecimals: false,
							listeners: {
								change: this.updateSplitInvoiceCheckbox,
								afterrender: this.updateSplitInvoiceCheckbox
							}
						}
					]
				}
			]
		});

		kDesktop.transportation3.transpEdit.loadUnloadPanel.superclass.constructor.call(this, config);

 		this.ownerModule.app.createReference(this);
	},
	updateSplitInvoiceCheckbox: function (field) {
		const form = field.up('form')
		const checkbox = form.down('#splitInvoiceCheckbox')

		if (!form || !checkbox) return

		const runRus = Ext.Number.from(form.down('[name=run_rus]').getValue(), 0)
		const runForeign = Ext.Number.from(form.down('[name=run_foreign]').getValue(), 0)

		const allowSplit = runRus > 0 && runForeign > 0

		if (!allowSplit && checkbox.getValue()) {
			checkbox.setValue(false) // сбросить галку, если стояла
		}

		checkbox.setDisabled(!allowSplit)
	},

	attachTooltipToGrid: function(grid) {
		const view = grid.getView()

		view.on('render', function(view) {
			view.tip = Ext.create('Ext.tip.ToolTip', {
				target: view.el,
				delegate: view.cellSelector,
				cls: 'handling-grid__custom-tooltip',
				autoHide: false,
				listeners: {
					beforeshow: {
						fn: function(tip) {
							const msg = Ext.get(tip.triggerElement).dom.childNodes[0].innerHTML
							tip.update(msg.replace(/\n/g, '<br/>'))
						},
						scope: this
					}
				}
			})
		}, this)
	},
	/**
	 * Назначает обработчик клика по ячейке в гриде, открывающий модальное окно для выбора даты и времени.
	 * После выбора значений, они сохраняются в запись, и если доступны обе даты (план и факт),
	 * отправляется асинхронный запрос на бэкенд для получения разницы между ними.
	 *
	 * @param {Ext.grid.Panel} grid — грид, к которому применяется логика
	 * @param {Object} fieldMap — отображение dataIndex поля грида в заголовок модального окна
	 * @param {String} [modalXtype='form.dateTimeEditModal'] — xtype модального окна
	 */
	createDateTimeCellClickHandler: function (grid, fieldMap, modalXtype = 'form.dateTimeEditModal') {
		grid.on('cellclick', function (view, td, cellIndex, record) {
			const column = view.getHeaderCt().getHeaderAtIndex(cellIndex)
			const field = column.dataIndex
			const title = fieldMap[field]

			if (!title) return

			const cellBox = Ext.fly(td).getBox()

			Ext.create(modalXtype, {
				title,
				record,
				fieldName: field,
				listeners: {
					/**
					 * Обработка применения новой даты/времени из модального окна.
					 * Сохраняем данные в запись и, если есть план + факт, получаем разницу.
					 */
					apply: async ({value, date, time, record, field}) => {
						// Сохраняем выбранное значение
						record.set(field, value)

						// Определяем, загрузка или выгрузка
						const isLoading = field.startsWith('loading_')
						const isOffloading = field.startsWith('offloading_')
						if (!isLoading && !isOffloading) return

						const baseField = isLoading ? 'loading_date_time' : 'offloading_date_time'
						const factField = baseField + '_fact'

						// Обновляем вспомогательные поля (отдельно дата и время)
						if (field === baseField) {
							record.set('date', date)
							record.set('time', time)
						} else if (field === factField) {
							record.set('date_fact', date)
							record.set('time_fact', time)
						}

						// Получаем обе даты
						const planDate = record.get(baseField)
						const factDate = record.get(factField)

						// Отправляем запрос, если обе даты заданы
						if (planDate && factDate) {
							const diff = await transportations.resources.getPlanFactDateDiff(planDate, factDate)
							if (diff) {
								record.set('plan_fact_date_diff', diff)
							}
						}
					},
					scope: this
				}
			}).showAt([cellBox.x, cellBox.y + cellBox.height])
		})
	},
	renderDateTime: function (value, record, fieldName) {
		let dateTime = value

		if (!(dateTime instanceof Date)) {
			dateTime = record.get(fieldName)
			if (typeof dateTime === 'string') dateTime = new Date(dateTime)
		}

		return dateTime instanceof Date && !isNaN(dateTime)
			? Ext.Date.format(dateTime, 'd.m.Y H:i')
			: ''
	},
	/**
	 * Добавляет данные в loadStore и unloadStore при создании новой сделки
	 */
	addLoadUnloadFromData: function(newDealData) {
		if (!newDealData) return

		const {
			load = '',
			fromplace = '',
			offload = '',
			toplace = ''
		} = newDealData

		let loadExists = load || fromplace
		let offloadExists = offload || toplace

		// Добавляем запись в loadStore (Загрузка)
		if (loadExists) {
			this.loadStore.add({
				id: 1,
				extid: 0,
				date: load ? Ext.Date.parse(load, 'd.m.Y H:i') : null,
				address: fromplace || ''
			})
		}

		// Добавляем запись в unloadStore (Выгрузка)
		if (offloadExists) {
			this.unloadStore.add({
				id: 1,
				extid: 0,
				date: offload ? Ext.Date.parse(offload, 'd.m.Y H:i') : null,
				address: toplace || ''
			})
		}
	},

	addLoadUnload: function(store, type = '') {
		let maxId = 0;
		if (store.getCount() > 0) {
			maxId = store.getAt(0).get('id');
			store.each(function(rec) {
				maxId = Math.max(maxId, rec.get('id'));
			})
		}

		maxId++;

		let model = {
			id: maxId,
			date: null,
			time: null,
			extid: 0,
			date_fact: null,
			time_fact: null,
		}

		if (type === 'load') {
			model = {
				...model,
				loading_date_time: null,
				loading_date_time_fact: null,
			}
		} else if (type === 'offload') {
			model = {
                ...model,
                offloading_date_time: null,
				offloading_date_time_fact: null,
            }
		}

		const newRecord = new store.model(model)
		newRecord.dirty = true
		newRecord.phantom = true
		store.add(newRecord)
	}
})

Ext.define('kDesktop.transportation3.transpEdit.loadUnloadPanel.favoriteWnd', {
	extend: 'Ext.window.Window',
	constructor: function(config) {
		config = config || {};

		this.ownerModule = config.ownerModule;
		this.parent = config.parent;

		this.addressStore = Ext.create('Ext.data.Store', {
			root: 'items',
			idProperty: 'id',
			remoteSort: true,
			autoLoad: true,
			fields: [
				'id',
				'address',
				'contacts'
			],
			proxy: {
				actionMethods: 'POST',
				type: 'ajax',
				url: this.ownerModule.app.connectUrl,
				extraParams: {
					module: this.ownerModule.moduleId,
					method: 'clientLoadFavoriteStore',
					id: this.parent.parent.clientPanel.clientCmb.getValue()
				},
				reader: {
					type: 'json',
					root: 'items',
					totalProperty: 'totalCount'
				}
			},
			sorters: [{
				property: 'id',
				direction: 'ASC'
			}]
		});

		this.grid = Ext.create('Ext.grid.Panel', {
			store: this.addressStore,
			loadMask: true,
			columns:[
				{
					header: "Адрес",
					dataIndex: 'address',
					width: 350,
					sortable: false
				},
				{
					header: "Контактное лицо",
					dataIndex: 'contacts',
					width: 350,
					sortable: false
				}
			],
			viewConfig: {
				stripeRows: true
			},
			selModel: {
			selType: 'cellmodel'
			}
		});
		this.grid.getView().on('render', function(view) {
			view.tip = Ext.create('Ext.tip.ToolTip', {
				target: view.el,
				delegate: view.cellSelector,
				trackMouse: true,
				autoHide: false,
				listeners: {
					'beforeshow': {
						fn: function(tip){
							var msg;
							var record = this.grid.getView().getRecord(tip.triggerElement.parentNode);
							msg = Ext.get(tip.triggerElement).dom.childNodes[0].innerHTML;
							tip.update(msg.replace(/\n/g, '<br/>'));
						},
						scope: this
					}
				}
			});
		}, this);
		this.grid.on('itemdblclick', function(view, rec, item, index, eventObj, options) {
			this.data.set('address', rec.get('address'))
			this.data.set('contacts', rec.get('contacts'))
			this.close();
		}, this);

		Ext.applyIf(config, {
			title: 'Избранные',
			width: 800,
			height: 400,
			modal: true,
			plain: true,
			border: false,
			layout: 'fit',
			items: [
				this.grid
			],
			buttons: [
				{
					text: 'Закрыть',
					iconCls: 'close-icon',
					scope: this,
					handler: function(){
						this.close();
					}
				}
			]
		});

		kDesktop.transportation3.transpEdit.loadUnloadPanel.favoriteWnd.superclass.constructor.call(this, config);
	}
});

Ext.define('kDesktop.transportation3.transpEdit.ferryPanel', {
	extend: 'Ext.form.Panel',
	initComponent: function() {
		this.addEvents('onUpdateTruckCounters')

		this.callParent()

		// При смене контракта - обновляем валюту и курс
		this.on('onSelectContract', this.onSelectContract, this)
		this.on('onUpdateTruckCounters', this.updateTruckCounters, this)

		this.counters = {
			cars: 0,
			drivers: 0,
		}
		// Перевозчик, выбранный на момент загрузки
		const carrierId = this.parent?.data?.data?.ferryman ?? null
		if (!carrierId)
			return;

		// Загружаем счетчики для кнопок Список машин и Список водителей, если Перевозчик - выбран
		this.loadTruckCounters(carrierId)
	},
	loadTruckCounters: async function(carrierId) {
		const counters = await helpers.transportation.getTruckCounters(carrierId)
		this.updateTruckCounters(counters)
	},
	onSelectContract(combo, record) {
		const contract = record[0] ?? record
		const cur = contract.get('currency')
		const ferryCurrencyFld = this.down('#ferryCurrencyFld')
		if (ferryCurrencyFld) {
			ferryCurrencyFld.setValue(cur)
		}

		const ferryCurrencyRateFld = this.down('#ferryCurrencyRateFld')
		const rate = cur === 'RUR' ? '1' : contract.get('rate')
		if (ferryCurrencyRateFld) {
			ferryCurrencyRateFld.setValue(rate)
		}

		this.payStr()
	},
	// Обновляем счетчики
	updateTruckCounters({
							carsCount = 0,
							driversCount = 0,
							onlyCars = false,
							onlyDrivers = false
	}) {
		if (carsCount !== this.counters.cars && !onlyDrivers) {
			const newCarsCount = carsCount ?? 0
			this.counters.cars = newCarsCount
			this.down('[ref=showSelectCarModalBtn]').setText(`Список машин (${newCarsCount})`)
		}
		if (driversCount !== this.counters.drivers && !onlyCars) {
			const newDriversCount = driversCount ?? 0
			this.counters.drivers = newDriversCount
			this.down('[ref=showSelectDriverModalBtn]').setText(`Список водителей (${newDriversCount})`)
		}
	},
	constructor: function(config) {
		config = config || {};
		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.priv = this.parent.priv;
		this.permissions = config?.permissions ?? {}
		this.clientConfig = config?.clientConfig ?? {}
		this.transportTypeList = this.clientConfig?.transportTypeList ?? []
		this.firstLoad = true

		const RolesHelper = helpers.roles
		const isCarrierFieldHidden = RolesHelper.isFieldHidden(
			this.permissions,
			RolesHelper.RESOURCE_TRANSPORTATIONS,
			'ferryman_name'
		)

		const data = this.parent?.data?.data ?? {} // Модель из базы
		// const carrierId = data?.ferryman ?? null // Перевозчик, выбранный на момент загрузки

		if (isCarrierFieldHidden) {
			// Store Перевозчиков
			this.carrierStore = Ext.create('Ext.data.Store', {
				fields: ['id', 'name'],
				data: []
			})

			//  Store для селектора Гос. номеров а/м
			this.carLicensePlateNumbersStore = Ext.create('Ext.data.Store', {
				fields: ['id'],
				data: []
			})

			// Store для селектора ФИО водителя
			this.driverFioStore = Ext.create('Ext.data.Store', {
				fields: ['id'],
				data: []
			})
		}

		if (!isCarrierFieldHidden) {
			this.carrierStore = Ext.create('Ext.data.Store', {
				autoLoad: false,
				fields: ['id', 'name', 'nds'],
				proxy: {
					actionMethods: 'POST',
					type: 'ajax',
					url: 'index.php',
					extraParams: {
						module: 'statistics',
						method: 'getCarrierList',
					},
					reader: {
						type: 'json',
						root: 'items',
						totalProperty: 'totalCount'
					}
				}
			})
		}

		const ferryCarIsShowed = !RolesHelper.isFieldHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS, 'ferrycar') // TODO
		const ferrycarPpIsShowed = !RolesHelper.isFieldHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS, 'ferrycarpp')
		const typetsIsShowed = !RolesHelper.isFieldHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS, 'typets')

		const carrierContract = {
			currency: data?.ferry_currency ?? '',
			payType: data?.carrier_contract_pay_type,
		}

		const carrierContractCurrencyString =
			helpers.transportationContract.getTransportationContractorCurrency(carrierContract.payType, carrierContract.currency, true)

		const ButtonHelper = helpers.button

		const carrierContractCombobox = Ext.create('contractEnhancedCombobox', {
			name: 'ferrycontract',
			ref: 'ferryContractCmb',
			actionName: 'ferryContractStore',
			contractorId: this.parent.data.data.ferryman,
			tid: this.parent.oid,
			initialValue: this.parent?.data?.data?.ferrycontract ?? null,
			width: 870,
			labelWidth: 165,
			labelSeparator: '',
			fieldLabel: 'Договор',
			listeners: {
				onContractStoreLoaded: {
					fn: function () {
						this.procContractLimit() // TODO check
					},
					scope: this
				},
			}
		})

		const carrierCombobox = Ext.create('form.comboboxWithSearch', {
			xtype: 'comboboxwithsearch',
			ref: 'ferrymanCmb',
			name: 'ferryman',
			store: this.carrierStore,
			fieldLabel: 'Подрядчик',
			labelSeparator: '',
			width: 475,
			labelWidth: 165,
			minChars: 3,
			selectHandler: async (cmb, selectedRecord) => {
				const selectedCarrierId = cmb.getValue()
				// При смене перевозчика - необходимо очистить сторы и селекторы
				// машин и водителей, очистить в модели формы данные о
				// выбранной машине и водителе
				await helpers.transportation.onResetCarrier(this, selectedCarrierId)

				// Сбрасываем Селекторы (а также их сторы): Договор и Контактное лицо
				// (опции подгружаются когда нажимают стрелку)
				const contractorId = selectedCarrierId ? parseInt(selectedCarrierId) : null
				const needToLoadStore = false

				const carrierContractCombobox = this.down('contractenhancedcombobox')
				if (carrierContractCombobox) {
					carrierContractCombobox.fireEvent('resetAndUpdateStore', {
						contractorId,
						needToLoadStore,
					})
				}

				const carrierPersonCombobox = this.down('personenhancedcombobox')
				if (carrierPersonCombobox) {
					carrierPersonCombobox.fireEvent('resetAndUpdateStore', {
						contractorId,
						needToLoadStore,
					})
				}

				this.parent.ferryNds()

				// Добавляем вызов обновления счетчиков
				if (selectedCarrierId) {
					const truckCounters =
						await helpers.transportation.getTruckCounters(selectedCarrierId)
					this.updateTruckCounters(truckCounters)
				}
			}
		})

		const carrierPersonCombobox = Ext.create('personEnhancedCombobox', {
			initialValue: this.parent?.data?.data?.ferrymanperson ?? null,
			contractorId: this.parent.data.data.ferryman,
			actionName: 'ferryPersonStore',
			tid: this.parent.oid,
			name: 'ferrymanperson',
			fieldLabel: 'Контактное лицо',
		})

		Ext.applyIf(config, {
			closable: false,
			autoScroll: true,
			frame: true,
			defaults: { xtype: 'container', layout: { type: 'hbox'} },
			items: [
				{
					items: [
						...RolesHelper.filterFormFields([
							{
								xtype: 'container',
								alias: 'ferryman_name',
								layout: 'hbox',
								width: 475,
								style: { marginRight: '30px'},
								items: [ carrierCombobox ]
							},
							{
								xtype: 'container',
								alias: 'ferryman_name',
								flex: 1,
								height: 50,
								width: '100%',
								layout: {
									type: 'hbox',
									align: 'stretch'
								},
								items: [
									{
										xtype: 'container',
										layout: 'vbox',
										flex: 1,
										items: [
											{
												xtype: 'button',
												width: 140,
												ref: 'showSelectCarModalBtn',
												text: 'Список машин',
												disabled: true,
												scope: this,
												handler: () => {
													const carrierId = this.ferrymanCmb.getValue()
													const selectCarModal = Ext.create('car.selectCarModal', {
														permissions: this?.permissions ?? {},
														clientConfig: this?.clientConfig ?? {},
														carrierId,
														listeners: {
															onCarSelect: (component, { car = {} }) => helpers.transportation.onCarChange(this, car),
															onUpdateCarsCounter: async () => this.updateTruckCounters(await helpers.transportation.getTruckCounters(carrierId, true, false))
														}
													})

													selectCarModal.show()
												},
												listeners: {
													afterrender: function (btn) {
														ButtonHelper.disableButtonIfNoComboValue(btn, 'ferrymanCmb')
													}
												}
											},
											{
												xtype: 'displayfield',
												style: {
													whiteSpace: 'nowrap'
												},
												value: !!this.parent.data.data.ferrycar_id
													? ''
													: '<span style="color:#ff0000">Машина не прикреплена</span>',
												ref: 'carIdCaptionFld'
											}
										]
									},
									{
										xtype: 'container',
										layout: 'vbox',
										flex: 1,
										items: [
											{
												xtype: 'button',
												width: 140,
												ref: 'showSelectDriverModalBtn',
												text: 'Список водителей',
												disabled: true,
												scope: this,
												handler: () => {
													const carrierId = this.ferrymanCmb.getValue()
													const selectDriverModal = Ext.create('driver.selectDriverModal', {
														permissions: this?.permissions ?? {},
														clientConfig: this?.clientConfig ?? {},
														carrierId,
														listeners: {
															onDriverSelect: (component, { driver = {} }) => helpers.transportation.onDriverChange(this, driver),
															onUpdateDriversCounter: async () => this.updateTruckCounters(await helpers.transportation.getTruckCounters(carrierId, false, true))
														}
													})

													selectDriverModal.show()
												},
												listeners: {
													afterrender: function (btn) {
														ButtonHelper.disableButtonIfNoComboValue(btn, 'ferrymanCmb')
													}
												}
											},
											{
												xtype: 'displayfield',
												style: {
													whiteSpace: 'nowrap'
												},
												value: !!this.parent.data.data.driver_id
													? ''
													: '<span style="color:#ff0000">Водитель не прикреплен</span>',
												ref: 'driverIdCaptionFld'
											}
										]
									},
									{
										xtype: 'hiddenfield',
										name: 'ferrycar_id',
										ref: 'carIdFld'
									},
									{
										xtype: 'hiddenfield',
										name: 'driver_id',
										ref: 'driverIdFld'
									}
								]
							},
						], this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS),
					]
				},
				{
					items: [
						...RolesHelper.filterFormFields([
							carrierContractCombobox
						], this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS),
					]
				},
				{
					items: [
						...RolesHelper.filterFormFields([
							carrierPersonCombobox
						], this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS),
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 170, value: 'Маршрут (откуда/куда)'},
						{
							xtype: 'textfield',
							name: 'ferryfromplace',
							ref: 'ferryFromPlaceFld',
							width: 350
						},
						{
							xtype: 'textfield',
							name: 'ferrytoplace',
							ref: 'ferryToPlaceFld',
							width: 350
						}
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 170, value: 'Стоимость для клиента'},
						{
							xtype: 'numberfield',
							name: 'ferryclientprice',
							width: 120,
							minValue: 0,
							decimalPrecision: 2,
							hideTrigger:true
						},
						{xtype: 'displayfield', width: 80, value: ''},
						{xtype: 'displayfield', width: 150, value: 'Фактический подрядчик'},
						{
							xtype: 'textfield',
							name: 'ferryman_fact',
							width: 350
						}
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 170, value: 'Номер пломбы'},
						{
							xtype: 'textfield',
							name: 'ferrystampnumber',
							width: 200

						},
						{xtype: 'displayfield', width: 70, value: ''},
						{xtype: 'displayfield', width: 230, value: 'Номер накладной(ТН, ТТН, Торг-12)'},
						{
							xtype: 'textfield',
							name: 'ferrywaybill',
							width: 200
						}
					]
				},
				...RolesHelper.filterFormFields([
					{
						items: [
							{
								xtype: 'textfield',
								name: 'ferrycar',
								fieldLabel: 'Марка а/м',
								labelSeparator: '',
								ref: 'ferrycarFld',
								width: 370,
								labelWidth: 165,
								readOnly: true,
								style: {
									marginRight: '70px'
								}
							},
							{
								xtype: 'container',
								layout: {
									type: 'hbox',
									align: isCarrierFieldHidden ? 'center' : 'stretch'
								},
								width: 440,
								height: 22,
								items: [
									!isCarrierFieldHidden
										? {
											xtype: 'customcombobox',
											name: 'ferrycar_id',
											ref: 'carLicensePlateNumberSelect',
											fieldLabel: 'Гос.номер а/м',
											labelSeparator: '',
											queryMode: 'local',
											autoLoad: false,
											width: ferryCarIsShowed ? 410 : 350,
											labelWidth: ferryCarIsShowed ? 225 : 165,
											displayField: 'ferrycarnumber',
											valueField: 'id',
											minChars: 3,
											forceSelection: false,
											store: this.carLicensePlateNumbersStore,
											getSelectedId: () => data?.ferrycar_id ?? null,
											getCarrierId: () => this.ferrymanCmb?.value ?? null,
											listeners: {
												select: {
													fn: (cmb, record) => {
														const car = record?.[0]?.data ?? {}
														helpers.transportation.onCarChange(this, car, false)
													}
												}
											}
										}
										: {
											xtype: 'textfield',
											fieldLabel: 'Гос.номер а/м',
											labelSeparator: '',
											width: ferryCarIsShowed ? 430 : 350,
											labelWidth: ferryCarIsShowed ? 225 : 165,
											readOnly: true,
											value: data?.ferrycarnumber ?? ''
										},
									{
										xtype: 'button',
										text: '',
										iconCls: 'add-icon',
										alias: 'ferryman_name',
										disabled: true,
										style: {
											margin: '0'
										},
										scope: this,
										handler: () => {
											const carrierId = this.ferrymanCmb?.value ?? null
											const modal = Ext.create('car.modal', {
												mode: 'new',
												data: null,
												permissions: this.permissions,
												clientConfig: this.clientConfig,
												carrierId,
												listeners: {
													onCarUpdate: async () => {
														if (carrierId) {
															this.updateTruckCounters(await helpers.transportation.getTruckCounters(carrierId, true, false))
														}

														this.carLicensePlateNumbersStore.load()
													},
												}
											})

											modal.show()
										},
										listeners: {
											afterrender: function (btn) {
												ButtonHelper.disableButtonIfNoComboValue(btn, 'ferrymanCmb')
											}
										}
									}
								]
							},
						]
					},
					{
						items: [
							{
								xtype: 'textfield',
								name: 'ferrycarpp',
								ref: 'ferrycarppFld',
								width: 370,
								labelWidth: 165,
								fieldLabel: 'Марка п/п',
								labelSeparator: '',
								readOnly: true,
								style: {
									marginRight: '70px'
								}
							},
							{
								xtype: 'textfield',
								name: 'ferrycarppnumber',
								ref: 'ferrycarppnumberFld',
								width: ferrycarPpIsShowed ? 430 : 370,
								labelWidth: ferrycarPpIsShowed ? 225 : 165,
								fieldLabel: 'Гос.номер п/п',
								labelSeparator: '',
								readOnly: true
							}
						]
					},
					{
						items: [
							{
								xtype: 'container',
								layout: {
									type: 'hbox',
									align: isCarrierFieldHidden ? 'center' : 'stretch'
								},
								width: 440,
								height: 22,
								items: [
									!isCarrierFieldHidden
										? {
											xtype: 'customcombobox',
											name: 'driver_id',
											ref: 'driverFioSelect',
											fieldLabel: 'ФИО водителя',
											labelSeparator: '',
											width: 370,
											queryMode: 'local',
											labelWidth: 165,
											displayField: 'fio',
											autoLoad: false,
											valueField: 'id',
											minChars: 3,
											forceSelection: false,
											store: this.driverFioStore,
											getSelectedId: () => data?.driver_id ?? null,
											getCarrierId: () => this.ferrymanCmb?.value ?? null,
											listeners: {
												select: {
													fn: (cmb, record) => {
														const driver = record?.[0]?.data ?? {}
														helpers.transportation.onDriverChange(this, driver, false)
													}
												},
											}
										}
										: {
											xtype: 'textfield',
											fieldLabel: 'ФИО водителя',
											labelSeparator: '',
											width: 370,
											labelWidth: 165,
											readOnly: true,
											value: data?.ferryfiodriver ?? ''
										},
									{
										xtype: 'button',
										text: '',
										iconCls: 'add-icon',
										alias: 'ferryman_name',
										disabled: true,
										style: {
											margin: '0'
										},
										scope: this,
										handler: () => {
											const carrierId = this.ferrymanCmb?.value ?? null
											const modal = Ext.create('driver.modal', {
												mode: 'new',
												data: null,
												permissions: this.permissions,
												clientConfig: this.clientConfig,
												carrierId,
												listeners: {
													onDriverUpdate: async () => {
														if (carrierId) {
															this.updateTruckCounters(await helpers.transportation.getTruckCounters(carrierId, false, true))
														}

														this.driverFioStore.load()
													},
												}
											})

											modal.show()
										},
										listeners: {
											afterrender: function (btn) {
												ButtonHelper.disableButtonIfNoComboValue(btn, 'ferrymanCmb')
											}
										}
									},
								]
							},
							{
								xtype: 'textfield',
								name: 'ferryman_typets_str',
								ref: 'ferryman_typets_strFld',
								width: typetsIsShowed ? 430 : 370,
								labelWidth: typetsIsShowed ? 225 : 165,
								fieldLabel: 'Тип ТС',
								labelSeparator: '',
								readOnly: true
							},
							{
								xtype: 'textfield',
								name: 'ferryman_typets',
								ref: 'ferryman_typetsFld',
								hidden: true,
							}
						]
					},
					{
						items: [
							{
								xtype: 'textfield',
								name: 'ferryphone',
								ref: 'ferryphoneFld',
								fieldLabel: 'Контактный телефон',
								labelSeparator: '',
								width: 370,
								labelWidth: 165,
								readOnly: true
							}
						]
					},
					{
						items: [
							{
								xtype: 'container',
								layout: {type: 'vbox'},
								height: 65,
								items: [
									{xtype: 'displayfield', width: 170, value: 'Паспортные данные', alias: 'ferrypassport'},
									{
										xtype: 'button',
										alias: 'ferrypassport',
										text: 'Копировать',
										handler: () => {
											const carMake = this.ferrycarFld.getValue() ?? ''
											const vehiclePlateNumber = !isCarrierFieldHidden
												? this.carLicensePlateNumberSelect.getRawValue()
												: data?.ferrycarnumber ?? ''
											const trailerMake = this.ferrycarppFld.getValue() ?? ''
											const trailerPlateNumber = this.ferrycarppnumberFld.getValue() ?? ''
											const driverFullName = !isCarrierFieldHidden
												? this.driverFioSelect.getRawValue()
												: data?.ferryfiodriver ?? ''
											const driverPhone = this.ferryphoneFld.getValue() ?? ''
											const driverPassport = this.ferrypassportFld.getValue() ?? ''
											const textToCopy = `${carMake} ${vehiclePlateNumber}, ${trailerMake} ${trailerPlateNumber}\n${driverFullName}, ${driverPhone}\n${driverPassport}`
											this.ownerModule.app.copyToClipboard(textToCopy)
										}
									}
								]
							},
							{
								xtype: 'textarea',
								name: 'ferrypassport',
								ref: 'ferrypassportFld',
								width: 700,
								height: 65,
								readOnly: true
							}
						]
					},
					{
						height: 10
					},
					{
						items: [
							{
								xtype: 'numberfield',
								name: 'ferryothercharges',
								width: 290,
								fieldLabel: 'Прочие расходы',
								labelWidth: 165,
								labelSeparator: '',
								minValue: 0,
								decimalPrecision: 2,
								hideTrigger:true,
							},
							{
								xtype: 'displayfield',
								width: 135,
								value: carrierContractCurrencyString,
								style: {
									marginLeft: '8px',
									marginRight: '27px',
								}
							},
							{
								xtype: 'textfield',
								name: 'ferryotherchargestarget',
								width: 410,
								fieldLabel: 'Цель',
								labelSeparator: '',
								labelWidth: 100,
							}
						]
					},
					{
						items: [
							{
								xtype: 'numberfield',
								name: 'ferrypricenal',
								fieldLabel: 'Стоимость нал',
								labelSeparator: '',
								labelWidth: 165,
								width: 290,
								minValue: 0,
								decimalPrecision: 2,
								hideTrigger:true
							}
						]
					},
					{
						items: [
							{
								xtype: 'textfield',
								name: 'ferry_currency',
								ref: 'ferryCurrencyFld',
								itemId: 'ferryCurrencyFld',
								width: 230,
								fieldLabel: 'Валюта',
								labelSeparator: '',
								labelWidth: 165,
								allowBlank: true,
								readOnly: true
							},
							{
								xtype: 'numberfield',
								name: 'ferry_currency_sum',
								ref: 'ferryCurrencySumFld',
								width: 150,
								value: '0',
								minValue: 0,
								decimalPrecision: 2,
								hideTrigger: true,
								listeners: {
									change: {
										fn: this.currencyValue,
										scope: this
									}
								},
								style: {
									marginRight: '30px'
								}
							},
							{
								xtype: 'numberfield',
								name: 'ferry_currency_rate',
								ref: 'ferryCurrencyRateFld',
								itemId: 'ferryCurrencyRateFld',
								width: 200,
								fieldLabel: 'Курс план',
								labelSeparator: '',
								labelWidth: 65,
								value: '0',
								minValue: 0,
								decimalPrecision: 6,
								hideTrigger: true,
								listeners: {
									change: {
										fn: this.currencyValue,
										scope: this
									}
								},
								style: {
									marginRight: '70px'
								}
							},
							{
								xtype: 'numberfield',
								name: 'ferry_currency_total',
								ref: 'ferryCurrencyTotalFld',
								width: 190,
								fieldLabel: 'Итого',
								labelSeparator: '',
								labelWidth: 40,
								value: '0',
								minValue: 0,
								decimalPrecision: 4,
								hideTrigger: true
							}
						]
					},
					{
						items: [
							{
								xtype: 'combobox',
								name: 'ferrynds',
								ref: 'ferryNdsCmb',
								width: 255,
								fieldLabel: 'Способ оплаты',
								labelSeparator: '',
								labelWidth: 165,
								queryMode: 'local',
								displayField: 'name',
								valueField: 'id',
								editable: false,
								store: Ext.create('Ext.data.ArrayStore', {
									fields: [
										'id',
										'name'
									],
									data: [
										['NDS', '20% НДС'],
										['VAT_7_PERCENT', '7% НДС'],
										['VAT_5_PERCENT', '5% НДС'],
										['WONDS', 'без НДС'],
										['ZERONDS', '0% НДС']
									]
								}),
								listeners: {
									select: function() {
										this.payStr();
									},
									scope: this
								}
							}
						]
					},
					{
						items: [
							{xtype: 'displayfield', width: 170, value: 'Сроки оплаты'},
							{
								xtype: 'numberfield',
								name: 'ferrypayperiod',
								width: 40,
								minValue: 0,
								decimalPrecision: 0,
								hideTrigger:true
							},
							{xtype: 'displayfield', width: 50, value: ''},
							{xtype: 'displayfield', width: 60, value: 'Простой'},
							{
								xtype: 'combobox',
								name: 'ferrydowntime_currency',
								width: 80,
								queryMode: 'local',
								displayField: 'name',
								valueField: 'id',
								editable: false,
								store: Ext.create('Ext.data.ArrayStore', {
									fields: [
										'id',
										'name'
									],
									data: [
										['USD', 'USD'],
										['EUR', 'EUR'],
										['KZT', 'KZT'],
										['RUR', 'RUR'],
										['CNY' ,'CNY'],
										['UZS', 'UZS']
									]
								})
							},
							{xtype: 'displayfield', width: 30, value: ''},
							{xtype: 'displayfield', width: 50, value: 'Ед.изм'},
							{
								xtype: 'combobox',
								name: 'ferrydowntime_unit',
								width: 80,
								queryMode: 'local',
								displayField: 'name',
								valueField: 'id',
								editable: false,
								store: Ext.create('Ext.data.ArrayStore', {
									fields: [
										'id',
										'name'
									],
									data: [
										['day', 'Сутки'],
										['workday', 'Р.День'],
										['hour', 'Час']
									]
								})
							},
							{xtype: 'displayfield', width: 30, value: ''},
							{xtype: 'displayfield', width: 50, value: 'Кол-во'},
							{
								xtype: 'numberfield',
								name: 'ferrydowntime_value',
								width: 60,
								value: '0',
								minValue: 0,
								decimalPrecision: 0,
								hideTrigger: true
							},
							{xtype: 'displayfield', width: 30, value: ''},
							{xtype: 'displayfield', width: 50, value: 'Сумма'},
							{
								xtype: 'numberfield',
								name: 'ferrydowntime_sum',
								width: 90,
								value: '0',
								minValue: 0,
								decimalPrecision: 4,
								hideTrigger: true
							}
						]
					},
					{
						items: [
							{
								xtype: 'textarea',
								name: 'ferrypaycomment',
								fieldLabel: 'Примечания к оплате',
								labelSeparator: '',
								labelWidth: 165,
								ref: 'ferryPayCommentFld',
								width: 870,
								height: 70
							}
						]
					},
					{
						items: [
							{
								xtype: 'datefield',
								name: 'ferryinvoicedate',
								alias: 'ferryinvoicedate_str',
								fieldLabel: 'Дата и номер счета',
								labelSeparator: '',
								labelWidth: 165,
								width: 270,
								allowBlank: true,
								format: 'd.m.Y',
								editable: true,
								startDay: 1
							},
							{
								xtype: 'textfield',
								name: 'ferryinvoice',
								alias: 'ferryinvoicedate_str',
								width: 600
							}
						]
					},
					{
						items: [
							{
								xtype: 'datefield',
								name: 'ferryinvoice_actdate',
								fieldLabel: 'Дата и номер акта',
								alias: 'ferryinvoiceactdate_str',
								labelSeparator: '',
								labelWidth: 165,
								width: 270,
								allowBlank: true,
								format: 'd.m.Y',
								editable: true,
								startDay: 1
							},
							{
								xtype: 'textfield',
								alias: 'ferryinvoiceactdate_str',
								name: 'ferryinvoice_act',
								width: 600
							}
						]
					},
					{
						items: [
							{
								xtype: 'datefield',
								name: 'ferryinvoice_scfdate',
								alias: 'ferryinvoice_scf',
								fieldLabel: 'Дата и номер счф',
								labelSeparator: '',
								labelWidth: 165,
								width: 270,
								allowBlank: true,
								format: 'd.m.Y',
								editable: true,
								startDay: 1
							},
							{
								xtype: 'textfield',
								name: 'ferryinvoice_scf',
								width: 600
							}
						]
					},
					{
						xtype: 'datefield',
						name: 'ferry_plandate',
						alias: 'ferry_plandate_str',
						fieldLabel: 'Плановая дата оплаты',
						labelSeparator: '',
						labelWidth: 165,
						width: 270,
						allowBlank: true,
						format: 'd.m.Y',
						editable: false,
						startDay: 1
					}
				], this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS),
				{
					items: [
						{xtype: 'displayfield', width: 170, value: 'Внутренний комментарий'},
						{
							xtype: 'textarea',
							name: 'ferryman_internalcomment',
							width: 700,
							height: 40
						}
					]
				},
				{
					xtype : 'container',
					layout: {
						type: 'hbox'
					},
					items: [
						{xtype: 'displayfield', ref: 'ferrySnsFld'}
					]
				}
			],
		});

		kDesktop.transportation3.transpEdit.ferryPanel.superclass.constructor.call(this, config);

 		this.ownerModule.app.createReference(this);
	},
	currencyValue: function () {
		// Если курс валюты KZT - то в курсе * 100, и в ИТОГО соответственно делим на 100
		const isKZT = this.ferryCurrencyFld.getValue() === helpers.currencies.KZT
		if (this.ferryCurrencyRateFld && isKZT) {
			const currentRateValue = this.ferryCurrencyRateFld.getValue()
			if (currentRateValue && currentRateValue < 1) {
				const newRate = (currentRateValue * 100).toFixed(4)
				this.ferryCurrencyRateFld.setValue(newRate)
			}
		}

		if (!this.ferryCurrencyRateFld || !this.ferryCurrencySumFld || !this.ferryCurrencyTotalFld) return false
		const product = this.ferryCurrencyRateFld.getValue() * this.ferryCurrencySumFld.getValue()
		const value = !isKZT ? product : product / 100
		this.ferryCurrencyTotalFld.setValue(value)
	},
	payStr: function() {
		const carrierContractCombo = this.down('contractenhancedcombobox')?.down('combobox')

		if (!carrierContractCombo) {
			console.warn('Combobox не найден, проверьте структуру компонентов')
			return;
		}

		const selectedValue = carrierContractCombo.getValue()
		if (!selectedValue) {
			console.warn('Нет выбранного значения в combobox')
			return
		}

		const contract = carrierContractCombo.findRecordByValue(selectedValue)
		if (!contract) {
			console.warn('Запись с таким значением не найдена в store combobox')
			return
		}

		const nds = this.parent._s(this.ferryNdsCmb.getRawValue())

		if (contract && nds && nds.length) this.ferryPayCommentFld.setValue(
			'Безналичная оплата ' + nds +
			' на р/счет ' +
			this.parent._s(contract.get('payby')) +
			' верно оформленных товаросопроводительных и закрывающих (Счет, АВР, СФ, ТЗ, информационные письмо (в случае привлечения 3-го лица при межд. перевозке) документов в течение ' +
			this.parent._s(contract.get('paydelay')) +
			' банковских дней'
		)
	},
	procContractLimit: function() {
		// TODO check
		if (this.ferryContractCmb) {
			this.ferryContractCmb.removeCls('combobox-bold-red');

			var rec = this.ferryContractCmb.getValue()
			if (rec && (rec > 0)) rec = this.ferryContractCmb.store.findRecord('id', rec);
			if (rec && (rec.get('sl') > 90)) this.ferryContractCmb.addCls('combobox-bold-red');
		}
	}
});

Ext.define('kDesktop.transportation3.transpEdit.financePanel', {
	extend: 'Ext.panel.Panel',
	constructor: function(config) {
		config = config || {};

		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.priv = this.parent.priv;
		this.permissions = config?.permissions ?? {}

		this.gridStore = Ext.create('Ext.data.Store', {
			pageSize: 40,
				root: 'items',
				idProperty: 'id',
				remoteSort: true,
			autoLoad: true,
			fields: [
				'rowid',
				'id',
				'type',
				'type_str',
				'cash',
				'cash_str',
				'currency',
				'value',
				'payorder',
				'payorderdate',
				'userlogin',
				'date_str'
			],
			proxy: {
				actionMethods: 'POST',
				type: 'ajax',
				url: this.ownerModule.app.connectUrl,
				extraParams: {
					module: this.ownerModule.moduleId,
					method: 'financeGrid',
					tid: this.parent.oid
				},
				reader: {
					type: 'json',
					root: 'items',
					totalProperty: 'totalCount'
				}
			},
			sorters: [{
				property: 'id',
				direction: 'ASC'
			}]
		});

		this.grid = Ext.create('Ext.grid.Panel', {
			split: false,
			border: true,
			store: this.gridStore,
			loadMask: true,
			columnLines: true,
			columns:[
				{
					header: "",
					dataIndex: 'rowid',
					width: 70,
					sortable: false
				},
				{
					header: "",
					dataIndex: 'type_str',
					width: 180,
					renderer: function (value, metaData, record) {
						const PaymentsHelper = helpers.payments
						const valueFloat = parseFloat(record.get('value')) ?? 0
						const type = record.get('type') ?? null
						if (valueFloat >= 0) {
							if (type === PaymentsHelper.PAYMENT_INCOME) {
								return 'Поступление';
							} else if (type === PaymentsHelper.PAYMENT_OUTCOME) {
								return 'Списание';
							}
						} else {
							if (type === PaymentsHelper.PAYMENT_INCOME) {
								return 'Оплата штрафа клиенту';
							} else if (type === PaymentsHelper.PAYMENT_OUTCOME) {
								return 'Оплата штрафа подрядчиком';
							}
						}

						return '';
					},
					sortable: false
				},
				{
					header: "",
					dataIndex: 'cash_str',
					width: 100,
					sortable: false
				},
				{
					header: "",
					dataIndex: 'currency',
					width: 100,
					sortable: false
				},
				{
					header: "Сумма",
					dataIndex: 'value',
					width: 200,
					renderer: function (value) {
						const NumberHelper = helpers.number
						const valueFloat = parseFloat(value)
						const valueDecimal = NumberHelper.roundToDecimal(valueFloat)
						return NumberHelper.formatThousandsSeparatedBySpaces(valueDecimal)
					},
					sortable: false
				},
				{
					header: "Дата ПП",
					dataIndex: 'payorderdate',
					width: 200,
					sortable: false
				},
				{
					header: "Номер ПП",
					dataIndex: 'payorder',
					width: 200,
					sortable: false
				},
				{
					header: "Пользователь",
					dataIndex: 'userlogin',
					width: 100,
					sortable: false
				},
				{
					header: "Дата создания",
					dataIndex: 'date_str',
					width: 100,
					sortable: false
				}
			],
			viewConfig: {
				stripeRows: true
			},
			dockedItems: [{
				xtype: 'pagingtoolbar',
				store: this.gridStore,
				dock: 'bottom',
				displayInfo: true,
				displayMsg: 'Записи {0} - {1} из {2}',
				emptyMsg: "Нет записей"
			}]
		});

		this.grid.on('itemdblclick', (view, rec) => {
			this.edit({
				id: rec.get('id'),
				type: rec.get('type'),
				cash: rec.get('cash'),
				currency: rec.get('currency'),
				value: rec.get('value'),
				payorderdate: rec.get('payorderdate'),
				payorder: rec.get('payorder')
			}, this.permissions);
		}, this);

		const RolesHelper = helpers.roles
		const canAddIncomePayment = RolesHelper.isAbleToAddPaymentByType(
			this.permissions,
			RolesHelper.TARGET_SHOW_INCOME_PAYMENTS
		)
		const canAddOutcomePayment = RolesHelper.isAbleToAddPaymentByType(
			this.permissions,
			RolesHelper.TARGET_SHOW_OUTCOME_PAYMENTS
		)

		const addPaymentButtonIsHidden = !canAddIncomePayment && !canAddOutcomePayment

		this.grid.on('containercontextmenu', (view, eventObj) =>{
			const _contextMenu = Ext.create('Ext.menu.Menu', {
				items: [
					{
						text: 'Добавить',
						hidden: addPaymentButtonIsHidden,
						handler: () =>{
							this.edit({
								id: 0
							}, this.permissions);
						},
						scope: this
					}
				]
			});

			_contextMenu.showAt(eventObj.getXY());
			eventObj.stopEvent();
		}, this);

		this.grid.on('itemcontextmenu', (view, rec, node, index, eventObj) => {
			const _contextMenu = Ext.create('Ext.menu.Menu', {
				items: [
					{
						text: 'Добавить',
						hidden: addPaymentButtonIsHidden,
						handler: () => this.edit({
							id: 0
						}, this.permissions),
						scope: this
					},
					{
						text: 'Редактировать',
						handler: () =>{
							this.edit({
								id: rec.get('id'),
								type: rec.get('type'),
								cash: rec.get('cash'),
								currency: rec.get('currency'),
								value: rec.get('value'),
								payorderdate: rec.get('payorderdate'),
								payorder: rec.get('payorder')
							}, this.permissions);
						},
						scope: this
					},
					{
						text: 'Удалить',
						handler: function (){
							Ext.MessageBox.confirm('Удалить?', 'Вы уверены что хотите удалить эту запись?',
								function(btn){
									if(btn == 'yes') {
										this.ownerModule.app.doAjax({
											module: this.ownerModule.moduleId,
											method: 'delPayment',
											id: rec.get('id')
										},
										function(res) {
											this.gridStore.load();
										},
										this, this);
									}
								},
								this
							);
						},
						scope: this
					}
				]
			});
			_contextMenu.showAt(eventObj.getXY());
			eventObj.stopEvent();
		}, this);

		this.grid.getView().on('render', function(view) {
			view.tip = Ext.create('Ext.tip.ToolTip', {
				target: view.el,
				delegate: view.cellSelector,
				trackMouse: true,
				autoHide: false,
				listeners: {
					'beforeshow': {
						fn: function(tip){
							var msg;
							var record = this.grid.getView().getRecord(tip.triggerElement.parentNode);
							msg = Ext.get(tip.triggerElement).dom.childNodes[0].innerHTML;
							tip.update(msg.replace(/\n/g, '<br/>'));
						},
						scope: this
					}
				}
			});
		}, this);

		Ext.applyIf(config, {
			border: false,
			closable: false,
			layout: 'fit',
			items: [
				this.grid
			]
		});

		kDesktop.transportation3.transpEdit.financePanel.superclass.constructor.call(this, config);
	},

	edit: function(data, permissions) {
		Ext.create('kDesktop.transportation3.transpEdit.financePanel.editWnd', {
			ownerModule: this.ownerModule,
			parent: this,
			data: data,
			permissions
		}).show()
	},

	showMask: function(msg) {
		this.body.mask(msg + '...', 'x-mask-loading');
	},

	hideMask: function() {
		this.body.unmask();
	}
});

Ext.define('kDesktop.transportation3.transpEdit.financePanel.editWnd', {
	extend: 'Ext.window.Window',
	constructor: function(config) {
		config = config || {};

		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.data = config.data;
		this.permissions = config.permissions

		const RolesHelper = helpers.roles

		// Формируем список видов оплаты на базе пермишенов роли
		const paymentTypeStore = []
		if (RolesHelper.isAbleToAddPaymentByType(
			this.permissions,
			RolesHelper.TARGET_SHOW_INCOME_PAYMENTS
		)) {
			paymentTypeStore.push(['IN', 'Поступление'])
		}

		if (RolesHelper.isAbleToAddPaymentByType(
			this.permissions,
			RolesHelper.TARGET_SHOW_OUTCOME_PAYMENTS
		)) {
			paymentTypeStore.push(['OUT', 'Списание'])
        }

		this.mainForm = Ext.create('Ext.form.Panel', {
			border: false,
			frame: true,
			bodyStyle:'padding:5px;',
			items: [
				{
					xtype: 'hiddenfield',
					name: 'id',
					value: this.data.id
				},
				{
					xtype : 'container',
					layout: { type: 'hbox' },
					items: [
						{xtype: 'displayfield', width: 90, value: 'Тип'},
						{
							xtype: 'combobox',
							name: 'type',
							width: 280,
							queryMode: 'local',
							displayField: 'value',
							valueField: 'key',
							editable: false,
							allowBlank: false,
							disabled: this.data.id ? true : false,
							store:  Ext.create('Ext.data.ArrayStore', {
								fields: [
									'key',
									'value'
								],
								data: paymentTypeStore
							})
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 90, value: ''},
						{
							xtype: 'combobox',
							name: 'cash',
							width: 280,
							queryMode: 'local',
							displayField: 'name',
							valueField: 'id',
							allowBlank: false,
							editable: false,
							store: Ext.create('Ext.data.ArrayStore', {
								fields: [
									'id',
									'name'
								],
								data: [
									['0', 'Безнал'],
									['1', 'Нал']
								]
							})
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 90, value: 'Валюта'},
						{
							xtype: 'combobox',
							name: 'currency',
							width: 280,
							queryMode: 'local',
							displayField: 'name',
							valueField: 'id',
							allowBlank: false,
							editable: false,
							store: Ext.create('Ext.data.ArrayStore', {
								fields: [
									'id',
									'name'
								],
								data: [
									['USD', 'USD'],
									['EUR', 'EUR'],
									['KZT', 'KZT'],
									['RUR', 'RUR'],
									['CNY', 'CNY'],
									['UZS', 'UZS']
								]
							})
						}
					]
				},
				{
					xtype : 'container',
					layout: { type: 'hbox' },
					items: [
						{xtype: 'displayfield', width: 90, value: 'Сумма'},
						{
							xtype: 'numberfield',
							name: 'value',
							width: 280,
							// minValue: 0,
							decimalPrecision: 2,
							allowBlank: false,
							hideTrigger:true
						}
					]
				},
				{
					xtype : 'container',
					layout: { type: 'hbox' },
					items: [
						{xtype: 'displayfield', width: 90, value: 'Дата ПП'},
						{
							xtype: 'datefield',
							name: 'payorderdate',
							width: 100,
							allowBlank: true,
							format: 'd.m.Y',
							editable: false,
							startDay: 1
						}
					]
				},
				{
					xtype : 'container',
					layout: { type: 'hbox' },
					items: [
						{xtype: 'displayfield', width: 90, value: 'Номер ПП'},
						{
							xtype: 'textfield',
							name: 'payorder',
							width: 280
						}
					]
				}
			]
		});
		this.ownerModule.app.createReference(this.mainForm);

		Ext.applyIf(config, {
			title: 'Платеж',
			width: 405,
			autoHeight: true,
			modal: true,
			plain: true,
			border: false,
			items: [
				this.mainForm
			],
			buttons: [
				{
					text: 'Cохранить',
					iconCls: 'ok-icon',
					scope: this,
					handler: function(){
						this.save();
					}
				},
				{
					text: 'Отмена',
					iconCls: 'close-icon',
					scope: this,
					handler: function(){
						this.close();
					}
				}
			]
		});

		kDesktop.transportation3.transpEdit.financePanel.editWnd.superclass.constructor.call(this, config);

		this.on('afterrender', function() {
			if (this.data) this.mainForm.getForm().setValues(this.data);
		}, this);
	},

	save: function () {
		if (this.mainForm.getForm().isValid()) {
			Ext.MessageBox.confirm('Сохранение', 'Вы уверены что хотите сохранить?',
				function(btn){
					if(btn == 'yes') {
						var data = this.ownerModule.app.getFormValues(this.mainForm);

						this.ownerModule.app.doAjax({
							module: this.ownerModule.moduleId,
							method: 'savePayment',
							mode: this.mode,
							tid: this.parent.parent.oid,
							data: Ext.encode(data)
						},
						function(res) {
							this.parent.gridStore.load();
							this.close();
						},
						this, this);
					}
				},
				this
			);
		}
	},

	showMask: function(msg) {
		this.body.mask(msg + '...', 'x-mask-loading');
	},

	hideMask: function() {
		this.body.unmask();
	}
});

Ext.define('kDesktop.transportation3.transpEdit.docPanel', {
	extend: 'Ext.form.Panel',
	constructor: function(config) {
		config = config || {};

		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.priv = this.parent.priv;
		this.permissions = config?.permissions ?? {}
		this.clientConfig = config?.clientConfig ?? {}
		const formFields = [
			{
				items: [
					{
						xtype: 'datefield',
						name: 'clientdocdate',
						alias: 'clientdocdate_str',
						width: 400,
						labelWidth: 295,
						fieldLabel: 'Дата отправки документов клиенту',
						labelSeparator: '',
						allowBlank: true,
						format: 'd.m.Y',
						editable: true,
						startDay: 1
					},
					{
						xtype: 'combobox',
						name: 'clientdocdelivery',
						ref: 'clientDocdeliveryCmb',
						width: 250,
						queryMode: 'local',
						displayField: 'value',
						valueField: 'key',
						editable: false,
						store: Ext.create('Ext.data.JsonStore', {fields: ['key', 'value'], idProperty: 'key'})
					},
					{
						xtype: 'textfield',
						name: 'clientdoccomment',
						width: 230
					}
				]
			},
			{
				xtype: 'datefield',
				name: 'clientscandocdate',
				width: 400,
				fieldLabel: 'Дата отправки скан копий',
				labelWidth: 295,
				labelSeparator: '',
				allowBlank: true,
				format: 'd.m.Y',
				editable: true,
				startDay: 1
			},
			{
				xtype: 'datefield',
				name: 'clientorigdocdate',
				width: 400,
				fieldLabel: 'Дата получения клиентом оригиналов',
				labelWidth: 295,
				labelSeparator: '',
				allowBlank: true,
				format: 'd.m.Y',
				editable: true,
				startDay: 1
			},
			{
				xtype: 'datefield',
				name: 'ferrydocdate',
				alias: 'ferrydocdate_str',
				width: 400,
				fieldLabel: 'Дата получения документов от подрядчика',
				labelWidth: 295,
				labelSeparator: '',
				allowBlank: true,
				format: 'd.m.Y',
				editable: true,
				startDay: 1
			},
			{
				xtype: 'datefield',
				name: 'ferryscandocdate',
				width: 400,
				fieldLabel: 'Дата получения скан копий от подрядчика',
				labelWidth: 295,
				labelSeparator: '',
				allowBlank: true,
				format: 'd.m.Y',
				editable: true,
				startDay: 1
			},
			{
				xtype: 'datefield',
				name: 'upd_shipment_date',
				width: 400,
				fieldLabel: 'Дата отправки счета, УПД(ЭДО)',
				labelWidth: 295,
				labelSeparator: '',
				allowBlank: true,
				format: 'd.m.Y',
				editable: true,
				startDay: 1
			},
		]
		const RolesHelper = helpers.roles
		this.mainPanel = Ext.create('Ext.panel.Panel', {
			region: 'north',
			height: 180,
			border: false,
			frame: true,
			defaults: { xtype: 'container', layout: { type: 'hbox'} },
			items: RolesHelper.filterFormFields(formFields, this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS),
			bodyStyle: 'padding-top: 5px',
		});

		this.docGridStore = Ext.create('Ext.data.Store', {
			pageSize: 40,
			root: 'items',
			idProperty: 'id',
			remoteSort: true,
			autoLoad: true,
			fields: [
					'id',
					{name: 'status', type: 'int'},
					'date_str',
					'type',
					'type_str',
					'user_login',
					'url',
					'comment'
				],
			proxy: {
				actionMethods: 'POST',
				type: 'ajax',
				url: this.ownerModule.app.connectUrl,
				extraParams: {
					module: this.ownerModule.moduleId,
					method: 'docGrid',
					tid: this.parent.oid
				},
				reader: {
					type: 'json',
					root: 'items',
					totalProperty: 'totalCount'
				}
			},
			sorters: [{
				property: 'id',
				direction: 'ASC'
			}]
		});

		this.docGridTbar = Ext.create('Ext.toolbar.Toolbar', {
			items: [
				{
					text: 'Добавить',
					scope: this,
					handler: function (){
						this.editDoc({
							id: 0,
							comment: ''
						});
					}
				}
			]
		});

		this.docGrid = Ext.create('Ext.grid.Panel', {
			region: 'center',
			split: false,
			border: true,
			store: this.docGridStore,
			loadMask: true,
			columnLines: true,
			scope: this,
			columns:[
				{
					header: "Номер",
					dataIndex: 'id',
					width: 90,
					sortable: true
				},
				{
					header: "Дата",
					dataIndex: 'date_str',
					width: 90,
					sortable: true
				},
				{
					header: "Тип",
					dataIndex: 'type_str',
					width: 200,
					sortable: true
				},
				{
					header: "Пользователь",
					dataIndex: 'user_login',
					width: 150,
					sortable: true
				},
				{
					header: "",
					dataIndex: 'url',
					width: 70,
					sortable: false,
					renderer: function(value, metaData, record) {
						if (record.get('id')) return '<a target=\'_blank\' href=\''+this.scope.ownerModule.app.connectUrl+'?module='+this.scope.ownerModule.moduleId+'&method=downloadDoc&id='+record.get('id')+'\'>Скачать</a>';
						else return '';
					}
				},
				{
					header: "",
					dataIndex: 'status',
					width: 50,
					sortable: false,
					renderer: function(value, metaData, record) {
						if (value == 0)
							return 'Удален';
						else
							return '';
					}
				},
				{
					header: "Примечание",
					dataIndex: 'comment',
					width: 300,
					sortable: false
				}
			],
			viewConfig: {
				stripeRows: true
			},
			dockedItems: [{
				xtype: 'pagingtoolbar',
				store: this.docGridStore,
				dock: 'bottom',
				displayInfo: true,
				displayMsg: 'Записи {0} - {1} из {2}',
				emptyMsg: "Нет записей"
			}],
			tbar: this.docGridTbar,
		});
		this.docGrid.on('itemdblclick', function(view, rec, item, index, eventObj, options) {
			this.editDoc({
				id: rec.get('id'),
				type: rec.get('type'),
				comment: rec.get('comment')
			});
		}, this);
		this.docGrid.on('containercontextmenu', function(view, eventObj){
			var _contextMenu = Ext.create('Ext.menu.Menu', {
				items: [
					{
						text: 'Добавить',
						handler: function (){
							this.editDoc({
								id: 0,
								comment: ''
							});
						},
						scope: this
					}
				]
			});

			_contextMenu.showAt(eventObj.getXY());
			eventObj.stopEvent();
		}, this);
		this.docGrid.on('itemcontextmenu',function(view, rec, node, index, eventObj) {
			var _contextMenu = Ext.create('Ext.menu.Menu', {
				items: [
					{
						text: 'Добавить',
						handler: function (){
							this.editDoc({
								id: 0,
								comment: ''
							});
						},
						scope: this
					},
					{
						text: 'Редактировать',
						handler: function (){
							this.editDoc({
								id: rec.get('id'),
								type: rec.get('type'),
								comment: rec.get('comment')
							});
						},
						scope: this
					}
				]
			});
			if (this.priv && this.priv.transportation && this.priv.transportation.modDocDelete) _contextMenu.add(
				{
					text: 'Удалить',
					handler: function (){
						Ext.MessageBox.confirm('Удалить?', 'Вы уверены что хотите удалить эту запись?',
							function(btn){
								if(btn == 'yes') {
									this.ownerModule.app.doAjax({
										module: this.ownerModule.moduleId,
										method: 'delDoc',
										id: rec.get('id')
									},
									function(res) {
										this.docGridStore.load();
									},
									this, this);
								}
							},
							this
						);
					},
					scope: this
				}
			);
			_contextMenu.showAt(eventObj.getXY());
			eventObj.stopEvent();
		}, this);
		this.docGrid.getView().on('render', function(view) {
			view.tip = Ext.create('Ext.tip.ToolTip', {
				target: view.el,
				delegate: view.cellSelector,
				trackMouse: true,
				autoHide: false,
				listeners: {
					'beforeshow': {
						fn: function(tip){
							var msg;
							var record = this.docGrid.getView().getRecord(tip.triggerElement.parentNode);
							msg = Ext.get(tip.triggerElement).dom.childNodes[0].innerHTML;
							tip.update(msg.replace(/\n/g, '<br/>'));
						},
						scope: this
					}
				}
			});
		}, this);

		Ext.applyIf(config, {
			border: false,
			closable: false,
			layout: 'border',
			items: [
				this.mainPanel,
				this.docGrid
			]
		});

		kDesktop.transportation3.transpEdit.docPanel.superclass.constructor.call(this, config);

		this.ownerModule.app.createReference(this.mainPanel);
	},

	editDoc: function(data) {
		Ext.create('kDesktop.transportation3.transpEdit.docPanel.editDocWnd', {
			ownerModule: this.ownerModule,
			parent: this,
			data: data,
			clientConfig: this.clientConfig,
		}).show();
	},

	showMask: function(msg) {
		this.body.mask(msg + '...', 'x-mask-loading');
	},

	hideMask: function() {
		this.body.unmask();
	}
});

Ext.define('kDesktop.transportation3.transpEdit.docPanel.editDocWnd', {
	extend: 'Ext.window.Window',
	constructor: function(config) {
		config = config || {};

		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.data = config.data;
		this.clientConfig = config?.clientConfig ?? {}
		this.mainForm = Ext.create('Ext.form.Panel', {
			border: false,
			frame: true,
			bodyStyle:'padding:5px;',
			items: [
				{
					xtype: 'hiddenfield',
					name: 'id',
					value: this.data.id
				},
				{
					xtype : 'container',
					layout: {
						type: 'hbox'
					},
					items: [
						{xtype: 'displayfield', width: 90, value: 'Тип'},
						{
							xtype: 'combobox',
							name: 'type',
							width: 280,
							queryMode: 'local',
							displayField: 'value',
							valueField: 'key',
							editable: false,
							allowBlank: false,
							disabled: this.data.id ? true : false,
							store: Ext.create('Ext.data.Store', {
								fields: ['key', 'value'],
								data: helpers.docTypes.getDocTypeStore(this.clientConfig)
							}),
						}
					]
				},
				{
					xtype : 'container',
					layout: {
						type: 'hbox'
					},
					items: [
						{xtype: 'displayfield', width: 90, value: 'Примечание'},
						{
							xtype: 'textfield',
							width: 280,
							name: 'comment',
							disabled: this.data.id ? true : false
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					ref: 'uploadField',
					items: [
						{
							xtype: 'multiupload',
							accept: '*',
							width: 330,
							buttonConfig: {
								text: 'Прикрепить файл',
								iconCls: 'add-icon',
								margin: '10 0 0 0',
								containerWidth: 170
							},
							labelText: '&nbsp;'
						}
					]
				}
			]
		});
		this.ownerModule.app.createReference(this.mainForm);

		Ext.applyIf(config, {
			title: 'Документ',
			width: 405,
			autoHeight: true,
			modal: true,
			plain: true,
			border: false,
			items: [
				this.mainForm
			],
			buttons: [
				{
					text: 'Cохранить',
					iconCls: 'ok-icon',
					scope: this,
					handler: function(){
						this.save();
					}
				},
				{
					text: 'Отмена',
					iconCls: 'close-icon',
					scope: this,
					handler: function(){
						this.close();
					}
				}
			]
		});

		kDesktop.transportation3.transpEdit.docPanel.editDocWnd.superclass.constructor.call(this, config);

		this.on('afterrender', function() {
			if (this.data) this.mainForm.getForm().setValues(this.data);
		}, this);
	},

	save: function () {
		if (this.mainForm.getForm().isValid()) {
			Ext.MessageBox.confirm('Сохранение', 'Вы уверены что хотите сохранить?',
				function(btn){
					if(btn == 'yes') {
						var me = this;
						this.mainForm.getForm().submit({
							url: this.ownerModule.app.connectUrl,
							params: {
								module: this.ownerModule.moduleId,
								method: 'saveDoc',
								tid: me.parent.parent.oid
							},
							waitMsg: 'Сохраняется...',
							success: function(form, action) {
								me.parent.docGridStore.load();
								me.close();
							},
							failure: function(form, action) {
								Ext.Msg.alert('Ошибка', action.result.msg);
								me.mainForm.remove(me.mainForm.uploadField);
								me.mainForm.add({
									xtype : 'container',
									layout: 'hbox',
									ref: 'uploadField',
									items: [
										{
											xtype: 'multiupload',
											accept: '*',
											width: 380,
											buttonConfig: {
												text: 'Прикрепить файл',
												iconCls: 'add-icon',
												margin: '10 0 0 0',
												containerWidth: 170
											},
											labelText: '&nbsp;'
										}
									]
								});
								me.ownerModule.app.createReference(me.mainForm);
							}
						});
					}
				},
				this
			);
		}
	},

	showMask: function(msg) {
		this.body.mask(msg + '...', 'x-mask-loading');
	},

	hideMask: function() {
		this.body.unmask();
	}
});

Ext.define('kDesktop.transportation3.transpEdit.reportPanel', {
	extend: 'Ext.panel.Panel',
	constructor: function(config) {
		config = config || {};

		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.priv = this.parent.priv;

		this.store = Ext.create('Ext.data.Store', {
			pageSize: 40,
			root: 'items',
			idProperty: 'id',
			remoteSort: true,
			autoLoad: true,
			fields: [
				'id',
				'date_create',
				'date_str',
				'time_str',
				'owner_name',
				'data'
			],
			proxy: {
				actionMethods: 'POST',
				type: 'ajax',
				url: this.ownerModule.app.connectUrl,
				extraParams: {
					module: this.ownerModule.moduleId,
					method: 'reportGrid',
					tid: this.parent.oid
				},
				reader: {
					type: 'json',
					root: 'items',
					totalProperty: 'totalCount'
				}
			},
			sorters: [{
				property: 'date',
				direction: 'DESC'
			}]
		});

		this.gridBbar = Ext.create('Ext.toolbar.Paging', {
			store: this.store,
			displayInfo: true,
			displayMsg: 'Записи {0} - {1} из {2}',
			emptyMsg: "Нет записей"
		});

		this.grid = Ext.create('Ext.grid.Panel', {
			store: this.store,
			loadMask: true,
			columnLines: true,
			columns:[
				{
					header: "Дата",
					dataIndex: 'date_create',
					width: 150,
					sortable: false
				},
				{
					header: "Пользователь",
					dataIndex: 'owner_name',
					width: 150,
					sortable: false
				},
				{
					header: "Отчет",
					dataIndex: 'data',
					width: 450,
					sortable: false
				}
			],
			viewConfig: {
				stripeRows: true
			},
			tbar: this.gridTbar,
			bbar: this.gridBbar
		});

		this.grid.on('containercontextmenu', function(view, eventObj){
			if (this.parent.oid > 0) {
				var _contextMenu = Ext.create('Ext.menu.Menu', {
					items: [
						{
							text: 'Добавить отчет',
							iconCls: 'add-icon',
							scope: this,
							handler: function (){
								this.editReport({ tid: this.parent.oid });
							}
						}
					]
				});
				_contextMenu.showAt(eventObj.getXY());
			}
			eventObj.stopEvent();
		}, this);
		this.grid.on('itemcontextmenu',function(view, rec, node, index, eventObj) {
			if (this.parent.oid > 0) {
				var _contextMenu = Ext.create('Ext.menu.Menu', {
					items: [
						{
							text: 'Добавить отчет',
							iconCls: 'add-icon',
							scope: this,
							handler: function (){
								this.editReport({ tid: this.parent.oid });
							}

						},
						'-',
						{
							text: 'Редактировать',
							iconCls: 'edit-icon',
							scope: this,
							handler: function (){
								this.editReport({
									id: rec.get('id'),
									tid: this.parent.oid,
									data: rec
								});
							}
						}
					]
				});
				_contextMenu.showAt(eventObj.getXY());
			}
			eventObj.stopEvent();
		}, this);
		//this.grid.on('select', function(sm, record, rowIndex, eOpts){
		//	var template = this.buildInfoTpl(record.data);
		//	template.overwrite(this.infoPnl.body, record.data);
		//}, this);
		this.grid.getView().on('render', function(view) {
			view.tip = Ext.create('Ext.tip.ToolTip', {
				target: view.el,
				delegate: view.cellSelector,
				trackMouse: true,
				autoHide: false,
				listeners: {
					'beforeshow': {
						fn: function(tip){
							var msg;
							var record = this.grid.getView().getRecord(tip.triggerElement.parentNode);
							msg = Ext.get(tip.triggerElement).dom.childNodes[0].innerHTML;
							tip.update(msg.replace(/\n/g, '<br/>'));
						},
						scope: this
					}
				}
			});
		}, this);

		Ext.applyIf(config, {
			border: false,
			closable: false,
			layout: 'fit',
			items: [
				this.grid
			]
		});

		kDesktop.transportation3.transpEdit.reportPanel.superclass.constructor.call(this, config);
	},

	editReport: function(res) {
		Ext.create('kDesktop.transportation3.transpEdit.editReportWnd', { ownerModule: this.ownerModule, parent: this, data: res }).show();
	},

	showMask: function(msg) {
		this.body.mask(msg + '...', 'x-mask-loading');
	},

	hideMask: function() {
		this.body.unmask();
	}
});

Ext.define('kDesktop.transportation3.transpEdit.editReportWnd', {
	extend: 'Ext.window.Window',
	constructor: function(config) {
		config = config || {};

		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.data = config.data;

		this.mainForm = Ext.create('Ext.form.Panel', {
			border: false,
			frame: true,
			bodyStyle:'padding:5px;',
			items: [
				{
					xtype : 'container',
					layout: {
						type: 'hbox'
					},
					items: [
						{
							xtype: 'datefield',
							name: 'date_str',
							width: 100,
							allowBlank: false,
							format: 'd.m.Y',
							editable: false,
							startDay: 1,
							value: new Date()
						},
						{
							xtype: 'timefield',
							name: 'time_str',
							width: 60,
							allowBlank: false,
							format: 'H:i',
							//editable: false,
							startDay: 1,
							value: new Date()
						}
					]
				},
				{
					xtype: 'textarea',
					width: 500,
					height: 60,
					allowBlank: false,
					name: 'data'
				}
			]
		});
		this.ownerModule.app.createReference(this.mainForm);

		Ext.applyIf(config, {
			title: 'Отчет',
			width: 530,
			autoHeight: true,
			modal: true,
			plain: true,
			border: false,
			items: [
				this.mainForm
			],
			buttons: [
				{
					text: 'Cохранить',
					iconCls: 'ok-icon',
					scope: this,
					handler: function(){
						this.save();
					}
				},
				{
					text: 'Отмена',
					iconCls: 'close-icon',
					scope: this,
					handler: function(){
						this.close();
					}
				}
			]
		});

		kDesktop.transportation3.transpEdit.editReportWnd.superclass.constructor.call(this, config);

		this.on('afterrender', function() {
			if (this.data && this.data.data) this.mainForm.getForm().loadRecord(this.data.data);
		}, this);
	},

	save: function () {
		if (this.mainForm.getForm().isValid()) {
			Ext.MessageBox.confirm('Сохранение', 'Вы уверены что хотите сохранить эту запись?',
				function(btn){
					if(btn == 'yes') {
						var data = this.ownerModule.app.getFormValues(this.mainForm);

						this.ownerModule.app.doAjax({
							module: this.ownerModule.moduleId,
							method: 'editReport',
							id: this.data.id,
							tid: this.data.tid,
							data: Ext.encode(data)
						},
						function(res) {
							this.parent.gridBbar.doRefresh();
							Ext.getCmp('transportation_taskgrid1_pt').doRefresh();
							this.close();
						},
						this, this);
					}
				},
				this
			);
		}
	},

	showMask: function(msg) {
		this.body.mask(msg + '...', 'x-mask-loading');
	},

	hideMask: function() {
		this.body.unmask();
	}
});

Ext.define('kDesktop.transportation3.transpEdit.finePanel', {
	extend: 'Ext.form.Panel',
	constructor: function(config) {
		config = config || {};
		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.priv = this.parent.priv;

		const data = this.parent?.data?.data ?? {}
		const clientContract = {
			currency: data?.client_currency ?? '',
			payType: data?.client_contract_pay_type ?? '',
		}
		const carrierContract = {
			currency: data?.ferry_currency ?? '',
			payType: data?.carrier_contract_pay_type,
		}

		const TransportationContractHelper = helpers.transportationContract
		const clientContractCurrencyString =
			TransportationContractHelper.getTransportationContractorCurrency(clientContract.payType, clientContract.currency)
		const carrierContractCurrencyString =
			TransportationContractHelper.getTransportationContractorCurrency(carrierContract.payType, carrierContract.currency)

		Ext.applyIf(config, {
			closable: false,
			autoScroll: true,
			frame: true,
			defaults: { xtype: 'container', layout: { type: 'hbox'} },
			items: [
				{
					items: [
						{xtype: 'displayfield', width: 200, value: 'Сумма штрафа к клиенту<br>(платит клиент)'},
						{
							xtype: 'numberfield',
							name: 'finetoclient',
							width: 250,
							minValue: 0,
							decimalPrecision: 2,
							hideTrigger:true
						},
						{
							xtype: 'displayfield',
							width: 200,
							value: clientContractCurrencyString,
							style: {
								marginLeft: '12px',
							}
						},
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 200, value: 'Сумма штрафа от клиента<br>(платим мы)'},
						{
							xtype: 'numberfield',
							name: 'finefromclient',
							width: 250,
							minValue: 0,
							decimalPrecision: 2,
							hideTrigger:true
						},
						{
							xtype: 'displayfield', width: 200, value: clientContractCurrencyString, style: {
								marginLeft: '12px',
							}
						},
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 200, value: 'Примечания к штрафам клиента'},
						{
							xtype: 'textarea',
							name: 'clientfinedesc',
							width: 670,
							height: 70
						},
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 200, value: 'Сумма штрафа к подрядчику<br>(платит подрядчик)'},
						{
							xtype: 'numberfield',
							name: 'finetoferry',
							width: 250,
							minValue: 0,
							decimalPrecision: 2,
							hideTrigger:true
						},
						{
							xtype: 'displayfield', width: 200, value: carrierContractCurrencyString, style: {
								marginLeft: '12px',
							}
						},
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 200, value: 'Сумма штрафа от подрядчика<br>(платим мы)'},
						{
							xtype: 'numberfield',
							name: 'finefromferry',
							width: 250,
							minValue: 0,
							decimalPrecision: 2,
							hideTrigger:true
						},
						{
							xtype: 'displayfield', width: 200, value: carrierContractCurrencyString, style: {
								marginLeft: '12px',
							}
						},
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 200, value: 'Примечания к штрафам подрядчика'},
						{
							xtype: 'textarea',
							name: 'ferryfinedesc',
							width: 670,
							height: 70
						}
					]
				}
			]
		});

		kDesktop.transportation3.transpEdit.finePanel.superclass.constructor.call(this, config);

 		this.ownerModule.app.createReference(this);
	}
});

Ext.define('kDesktop.transportation3.transpEdit.calcPanel', {
	extend: 'Ext.panel.Panel',
	constructor: function(config) {
		config = config || {};

		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.priv = this.parent.priv;

		Ext.applyIf(config, {
			border: false,
			closable: false,
			layout: 'fit',
			items: [
				{
					xtype: 'textarea',
					ref: 'logArea',
					readOnly: true
				}
			]
		});

		kDesktop.transportation3.transpEdit.calcPanel.superclass.constructor.call(this, config);

		this.ownerModule.app.createReference(this);

		this.on('afterrender', function() {
			this.calc();
		}, this);
	},
	calc: function() {
		try {
			kDesktop.app.doAjax({
					module: this.ownerModule.moduleId,
					method: 'getCalculations',
					id: this.parent.oid
				},
				function(res) {
					this.logArea.setValue(res.log);
				},
				this);
		} catch (error) {
			console.error('Error in calc function:', error);
		}
	},
});
