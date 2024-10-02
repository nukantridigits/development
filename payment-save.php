Ext.define('kDesktop.transportation3', {
	extend: 'Ext.tab.Panel',
	moduleId: 'transportation3',
	constructor: function(config) {
		config = config || {};
		this.init =
		this.app = config.app;
		this.newWNDID = 0;
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
					clientConfig: config?.clientConfig ?? {}
				})
			]
		});

		kDesktop.transportation3.superclass.constructor.call(this, config);

		this.on('tabchange', this.onTabChange, this)
	},
	onTabChange: function(tabPanel, newTab) {
		const newTabUid = newTab?.uid ?? false
		if (newTabUid && newTabUid !== 'transportation-transportations') {
			helpers.transportation.onTransportationTabOpen(newTabUid)
		}
	},
	getTabItem: function(id) {
		let tab = null
        const items = this.items?.items ?? []
		if (!helpers.types.isArrayWithLength(items)) return null
		for (const item of items) {
			if (item?.uid == id || item?.oid == id) {
				tab = item
				break
			}
		}
		return tab
	},
	closeTabById: function(uid) {
		const tab = this.getTabItem(uid)
		if (tab) {
			this.remove(tab)
		} else {
			console.warn('Вкладка не найдена для uid:', uid);
		}
	},
	showMask: function(msg) {
		this.body.mask(msg + '...', 'x-mask-loading');
	},
	hideMask: function() {
		this.body.unmask();
	}
});

Ext.define('kDesktop.transportation3.transportationsTaskPanel', {
	extend: 'Ext.panel.Panel',
	constructor: function(config) {
		config = config || {};
		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.priv = this.ownerModule.priv;
		this.title = 'Задачи';
		this.closable = false;
		this.store = Ext.create('Ext.data.Store', {
			pageSize: 40,
			root: 'items',
			idProperty: 'id',
			remoteSort: true,
			autoLoad: true,
			fields: [
				'id',
				'obj',
				'objid',
				'type',
				'data'
			],
			proxy: {
				actionMethods: 'POST',
				type: 'ajax',
				url: this.ownerModule.app.connectUrl,
				extraParams: {
					module: this.ownerModule.moduleId,
					method: 'taskGrid'
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
			id: 'transportation_taskgrid1_pt',
			store: this.store,
			displayInfo: true,
			displayMsg: 'Записи {0} - {1} из {2}',
			emptyMsg: "Нет записей"
		});

		this.grid = Ext.create('Ext.grid.Panel', {
			region: 'center',
			store: this.store,
			loadMask: true,
			columnLines: true,
			columns:[
				{
					header: "&nbsp;",
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

		this.grid.on('itemdblclick', function(view, rec) {
			if ((rec.get('obj') === 'transportation') && (rec.get('type') === 'addReport')) {
				this.ownerModule.app.doAjax({
					module: 'configurations',
					method: 'getClientConfig',
					fields: 'showRegistersInContextMenu,transportTypeList'
				}, function(response) {
					const responseData = response?.data ?? {}
					const clientConfig = {
						showRegistersInContextMenu: responseData?.showRegistersInContextMenu === "1",
						transportTypeList: responseData?.transportTypeList ?? {}
					}
					this.parent.parent.onEditTransportation(rec.get('objid'), 'edit', null, clientConfig)
				}, this)
			}
		}, this)

		this.grid.on('containercontextmenu', function(view, eventObj){
			eventObj.stopEvent();
		}, this);
		this.grid.on('itemcontextmenu',function(view, rec, node, index, eventObj) {
			var _contextMenu = Ext.create('Ext.menu.Menu', {
				items: [
					{
						text: 'Выполнено',
						iconCls: 'ok-icon',
						scope: this,
						handler: function () {
							Ext.MessageBox.confirm('Отметка о выполнении', 'Сохранить отметку о выполнении?',
								function(btn){
									if(btn == 'yes') {
										this.ownerModule.app.doAjax({
											module: this.ownerModule.moduleId,
											method: 'closeTask',
											id: rec.get('id')
										},
										function(res) {
											this.gridBbar.doRefresh();
										},
										this, this);
									}
								},
								this
							);
						}

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
			layout: 'fit',
			items: [
				this.grid
			]
		});
		//

		kDesktop.transportation3.transportationsTaskPanel.superclass.constructor.call(this, config);
	},
	editReport: function(res) {
		Ext.create('kDesktop.transportation2.transpEdit.editReportWnd', { ownerModule: this.ownerModule, parent: this, data: res }).show();
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
		config = config || {};

		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.priv = this.parent.priv;
		this.clientConfig = config.clientConfig || {}
		this.showNbKzCurrencyRatesBlock = this.clientConfig?.showNbKzCurrencyRatesBlock ?? false
		this.title = 'Фильтр';
		this.closable = false;

		var width = Math.round(kDesktop.app.getWinWidth() / 4);
		this.taskPanel = Ext.create('kDesktop.transportation3.transportationsTaskPanel', {
			region: 'east',
			width: (width > 300) ? width : 300,
			collapsible: true,
			animCollapse: false,
			split: true,
			ownerModule: this.ownerModule, parent: this
		});
		let transportTypeList = this.clientConfig?.transportTypeList ?? []
		if (helpers.types.isObjectAndHasProps(transportTypeList)) {
			transportTypeList = helpers.data.convertObjectToStoreData(transportTypeList)
		}
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
							editable: false,
							startDay: 1
						},
						{xtype: 'displayfield', width: 24, value: '&nbsp;по'},
						{
							xtype: 'datefield',
							name: 'createdate2',
							width: 113,
							allowBlank: true,
							format: 'd.m.Y',
							editable: false,
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
						{xtype: 'displayfield', width: 120, value: 'Тип ТС'},
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
						{xtype: 'displayfield', width: 150, value: 'Клиент'},
						{
							xtype: 'combobox',
							width: 250,
							name: 'client',
							queryMode: 'remote',
							pageSize: 40,
							displayField: 'name',
							valueField: 'id',
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
										method: 'clientList'
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
						{xtype: 'displayfield', width: 120, value: 'Водитель'},
						{
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
						{xtype: 'displayfield', width: 150, value: 'Перевозчик'},
						{
							xtype: 'combobox',
							width: 250,
							name: 'ferryman',
							queryMode: 'remote',
							pageSize: 40,
							displayField: 'name',
							valueField: 'id',
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
										method: 'ferrymanList'
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
								this.parent.gridPanel.store.proxy.extraParams.filtr = Ext.encode(this.ownerModule.app.getFormValues(this.mainForm));
								this.parent.gridPanel.store.load();
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

								Ext.Ajax.request({
									url: 'index.php',
									method: 'POST',
									params: {
										module: 'transportation3',
										method: 'getDebitCredit',
										filter: filter
									},
									success: function (response) {
										try {
											const res = Ext.decode(response.responseText);
											if (res && res.data) {
												Ext.create('kDesktop.dcModal', {
													data: res.data
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
										console.error('AJAX request failed:', response);
										Ext.Msg.alert('Error', 'Server error: ' + response.statusText);
									},
									scope: this
								});
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
			if (this.data.regionDict) this.mainForm.searchRegionCmb.store.loadData(this.data.regionDict);

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

Ext.define('kDesktop.transportation3.transportationsGridPanel', {
	extend: 'Ext.panel.Panel',
	constructor: function(config) {
		const TypesHelper = helpers.types
		const RolesHelper = helpers.roles
		config = config || {};
		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		const configData = config?.data ?? {}
		this.priv = TypesHelper.isObjectAndHasProps(configData?.priv) ? configData.priv : {}
		this.permissions = TypesHelper.isObjectAndHasProps(configData?.permissions) ? configData.permissions : {}
		this.clientConfig = TypesHelper.isObjectAndHasProps(config?.clientConfig) ? config?.clientConfig : {}
		this.gridPalette = this.clientConfig?.gridCellColorsPalette ?? []
		const transpGridCellColors = TypesHelper.isObjectAndHasProps(this.clientConfig?.transpGridCellColors) ?
			this?.clientConfig?.transpGridCellColors : {}
		const transpStatusPalette = this.clientConfig?.transpStatusPalette ?? {}
		const storeFields = [
			'id',
			'idstr',
			{name: 'status', type: 'int'},
			'client_request',
			'manager',
			'typets_str',
			'ferryman_typets_str',
			{name: 'transp_status', type: 'int'},
			'transp_status_txt',
			'date_str',
			'logist_login',
			'load_str',
			'offload_str',
			{name: 'offloadchecked', type: 'int'},
			'direction',
			{name: 'client', type: 'int'},
			{name: 'clientnds', type: 'int'},
			'client_name',
			'client_accountant',
			'clientperson_phone',
			'clientperson_fio',
			'manager_login',
			'description',
			{name: 'clientpaid', type: 'int'},
			'clientpaidvalue',
			'clientinvoicedate_str',
			'clientdocdate_str',
			'ferryman_name',
			'ferrymanperson_phone',
			'ferrymanperson_fio',
			'ferrycar',
			'ferryfiodriver',
			'ferryprice',
			{name: 'ferrypaid', type: 'int'},
			'ferrypaidvalue',
			'ferryinvoicedate_str',
			'ferrydocdate_str',
			'profit',
			'profitpercent',
			'profitfact',
			'profitabilityfact',
			'delay_client',
			'delay_client_ind',
			'delay_ferry',
			'delay_ferry_ind',
			'client_plandate_str',
			'ferry_plandate_str',
			'directiontip',
			'ferrytip',
			'cargoprice',
			{name: 'ferrycheckmark', type: 'int'},
			{name: 'ferrycarcheckmark', type: 'int'},
			{name: 'ferrycarblmark', type: 'int'},
			{name: 'clientcheckmark', type: 'int'},
			{name: 'cargoinsurancemark', type: 'int'},
			'client_currency_sum',
			'client_currency',
			'ferry_currency_sum',
			'ferry_currency',
			'ferrynds',
			'client_sns',
			'ferry_sns',
			'last_report',
			'client_currency_leftpaym',
			'ferry_currency_leftpaym',
			'selectionModelChecked',
			'docTypeListPresence',
		]

		this.store = Ext.create('Ext.data.Store', {
			pageSize: 40,
			root: 'items',
			idProperty: 'id',
			remoteSort: true,
			autoLoad: true,
			fields: RolesHelper.filterStoreFields(storeFields, this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS),
			proxy: {
				actionMethods: 'POST',
				type: 'ajax',
				url: this.ownerModule.app.connectUrl,
				extraParams: {
					module: this.ownerModule.moduleId,
					unloadCheckFilter: config.unloadCheckFilter,
					method: 'transpGrid'
				},
				reader: {
					type: 'json',
					root: 'items',
					totalProperty: 'totalCount'
				}
			},
			sorters: [{
				property: 'idstr',
				direction: 'DESC'
			}]
		});

		this.store.on('load', function() {
			if ( (this.store.getTotalCount() > 0) && (this.grid) )
				this.grid.getSelectionModel().select(0);
			if (this.checkColumn) {
				this.checkColumn.checkedCount = 0;
				this.checkColumn.setHeaderChecked(false);
			}
			this.reCreateScroll();
		},this);

		this.gridBbar = Ext.create('Ext.toolbar.Paging', {
			store: this.store,
			displayInfo: true,
			displayMsg: 'Записи {0} - {1} из {2}',
			emptyMsg: "Нет записей",
			prependButtons: true,
			items: [
				{
					xtype: 'combobox',
					width: 50,
					queryMode: 'local',
					displayField: 'count',
					valueField: 'count',
					editable: false,
					value: 	this.store.pageSize,
					store: Ext.create('Ext.data.ArrayStore', {
						fields: [ 'count' ],
						data: [
							[ 40 ],
							[ 100 ],
							[ 200 ]
						]
					}),
					listeners: {
						select: {
							fn: function(cmb, rcrd, indx) {
								this.store.removeAll();
								this.store.pageSize = cmb.getValue();
								this.store.loadPage(1);
							},
							scope: this
						}
					}
				}
			]
		});

		this.checkColumn = Ext.create('kDesktop.transportation3.transportationsGridCheckColumn', {
			hidden: true,
			dataIndex: 'selectionModelChecked'
		});

		const TRANSP_GRID_ALIAS = 'transp_grid'
		const TRANSP_GRID_HIDDEN_COLS_ALIAS = 'transp_grid-hidden-cols'
		const getColumnWidth = (colName, defaultWidth = 100) => {
			const widths = helpers.localStorage.getStateValue(TRANSP_GRID_ALIAS, 'widths') ?? {}
			const colWidthLS = widths?.[colName] ?? null
			return colWidthLS ? parseInt(colWidthLS) : defaultWidth
		}
		const GridHelper = helpers.transportationGrid
		const cols = [
			this.checkColumn,
			{
				header: "ТК",
				dataIndex: 'ferrycheckmark',
				width: 40,
				sortable: false,
				renderer: (value, metaData) => {
					if (value === 0) metaData.style = `background-color : ${this.gridPalette[GridHelper.WARN_GRID_COLOR_KEY]} !important`
					if (value === 1) metaData.style = `background-color : ${this.gridPalette[GridHelper.SUCCESS_GRID_COLOR_KEY]} !important`

					return '&nbsp;';
				}
			},
			{
				header: "Авт",
				dataIndex: 'ferrycarcheckmark',
				width: 40,
				sortable: false,
				renderer: (value, metaData, record) => {
					if (value === 0) metaData.style = `background-color : ${this.gridPalette[GridHelper.WARN_GRID_COLOR_KEY]} !important`
					if (value === 1) metaData.style = `background-color : ${this.gridPalette[GridHelper.SUCCESS_GRID_COLOR_KEY]} !important`
					if (record.get('ferrycarblmark') === 1) metaData.style = "background-color : #000000 !important";

					return '&nbsp;'
				}
			},
			{
				header: "$",
				dataIndex: 'cargoprice',
				width: getColumnWidth('cargoprice', 50),
				sortable: false,
				align: 'right',
				renderer: function(value, metaData, record) {
					const docs = record.get('docTypeListPresence') ?? []
					metaData.style = helpers.transportationGrid.getCellBgColorByDocumentPresence('cargoprice', docs, transpGridCellColors)
					return value
				}
			},
			{
				header: "Номер",
				dataIndex: 'idstr',
				width: getColumnWidth('idstr'),
				sortable: true
			},
			{
				header: "№ клиент",
				dataIndex: 'client_request',
				width: getColumnWidth('client_request'),
				sortable: false
			},
			{
				header: "Тип",
				dataIndex: 'typets_str',
				width: getColumnWidth('typets_str', 50),
				sortable: true
			},
			{
				header: "Статус",
				dataIndex: 'transp_status_txt',
				width: getColumnWidth('transp_status_txt'),
				sortable: true,
				renderer: function(value, metaData, record) {
					const status = record.get('transp_status') ? parseInt(record.get('transp_status')) : 0
					metaData.style = helpers.transportationGrid.getTranspStatusCellStyle(status, transpStatusPalette)
					return value
				}
			},
			{
				header: "Дата создания",
				dataIndex: 'date_str',
				width: getColumnWidth('date_str', 120),
				sortable: true
			},
			{
				header: "Логист",
				dataIndex: 'logist_login',
				width: getColumnWidth('logist_login', 120),
				sortable: false
			},
			{
				header: "Дата загр",
				dataIndex: 'load_str',
				width: getColumnWidth('load_str', 120),
				sortable: true,
				renderer: function(value, metaData, record) {
					const docs = record.get('docTypeListPresence') ?? []
					metaData.style = helpers.transportationGrid.getCellBgColorByDocumentPresence('load_str', docs, transpGridCellColors)
					return value
				}
			},
			{
				header: "Дата выгр",
				dataIndex: 'offload_str',
				width: getColumnWidth('offload_str', 120),
				sortable: true,
				renderer: function(value, metaData, record) {
					const docs = record.get('docTypeListPresence') ?? []
					metaData.style = helpers.transportationGrid.getCellBgColorByDocumentPresence('offload_str', docs, transpGridCellColors)
					return value
						? (record.get('offloadchecked') == 1 ? value + ' <input type="button" class="x-form-field x-form-checkbox" style="background-position: 0 -13px;">' : value)
						: value
				}
			},
			{
				header: "Направление",
				dataIndex: 'direction',
				width: getColumnWidth('direction'),
				sortable: false
			},
			{
				header: "Клиент",
				dataIndex: 'client_name',
				width: getColumnWidth('client_name'),
				sortable: false,
				renderer: (value, metaData, record) => {
					if (record.get('clientcheckmark') === 0) metaData.style = `background-color : ${this.gridPalette[GridHelper.WARN_GRID_COLOR_KEY]} !important`
					return value
				}
			},
			{
				header: "Перевозчик",
				dataIndex: 'ferryman_name',
				width: getColumnWidth('ferryman_name'),
				sortable: false
			},
			{
				header: "Машина",
				dataIndex: 'ferrycar',
				width: getColumnWidth('ferrycar'),
				sortable: false
			},
			{
				header: "Водитель",
				dataIndex: 'ferryfiodriver',
				width: getColumnWidth('ferryfiodriver'),
				sortable: false
			},
			{
				header: "Местоположение",
				dataIndex: 'last_report',
				width: getColumnWidth('last_report'),
				sortable: false
			},
			{
				header: "Менеджер",
				dataIndex: 'manager_login',
				width: getColumnWidth('manager_login'),
				sortable: false
			},
			{
				header: "Характер груза",
				dataIndex: 'description',
				width: getColumnWidth('description'),
				sortable: false
			},
			{
				header: "Стоимость для клиента",
				dataIndex: 'client_currency_sum',
				width: getColumnWidth('client_currency_sum'),
				sortable: true
			},
			{
				header: "Валюта",
				dataIndex: 'client_currency',
				width: getColumnWidth('client_currency', 60),
				sortable: false
			},
			{
				header: "К оплате",
				dataIndex: 'client_currency_leftpaym',
				width: getColumnWidth('client_currency_leftpaym', 60),
				sortable: false
			},
			{
				header: "Оплачено полностью",
				dataIndex: 'clientpaidvalue',
				width: getColumnWidth('clientpaidvalue', 90),
				sortable: true,
				align: 'center',
				renderer: function(value, metaData, rec) {
					if (rec.get('clientpaid') == 1)
						return '<input type="button" class="x-form-field x-form-checkbox" style="background-position: 0 -13px;">';
					else
						return '';
				}
			},
			{
				header: "Бухгалтер",
				dataIndex: 'client_accountant',
				width: getColumnWidth('client_accountant', 90),
				sortable: false
			},
			{
				header: "Номер и дата счета",
				dataIndex: 'clientinvoicedate_str',
				width: getColumnWidth('clientinvoicedate_str', 130),
				sortable: false
			},
			{
				header: "Дата от-ки док-ов(кл)",
				dataIndex: 'clientdocdate_str',
				width: getColumnWidth('clientdocdate_str', 130),
				sortable: true
			},
			{
				header: "Стоимость(пр)",
				dataIndex: 'ferry_currency_sum',
				width: getColumnWidth('ferry_currency_sum'),
				sortable: true,
				renderer: function(value, metaData, record) {
					const docs = record.get('docTypeListPresence') ?? []
					metaData.style = helpers.transportationGrid.getCellBgColorByDocumentPresence('ferry_currency_sum', docs, transpGridCellColors)
					return value
				}
			},
			{
				header: "Способ оплаты",
				dataIndex: 'ferrynds',
				width: getColumnWidth('ferrynds', 60),
				sortable: false
			},
			{
				header: "Валюта",
				dataIndex: 'ferry_currency',
				width: getColumnWidth('ferry_currency', 60),
				sortable: false
			},
			{
				header: "К оплате",
				dataIndex: 'ferry_currency_leftpaym',
				width: getColumnWidth('ferry_currency_leftpaym', 60),
				sortable: false
			},
			{
				header: "Оплачено полностью",
				dataIndex: 'ferrypaidvalue',
				width: getColumnWidth('ferrypaidvalue', 90),
				sortable: true,
				align: 'center',
				renderer: function(value, metaData, rec) {
					if (rec.get('ferrypaid') == 1)
						return '<input type="button" class="x-form-field x-form-checkbox" style="background-position: 0 -13px;">';
					else
						return '';
				}
			},
			{
				header: "Номер и дата счета(пр)",
				dataIndex: 'ferryinvoicedate_str',
				width: getColumnWidth('ferryinvoicedate_str', 130),
				sortable: false
			},
			{
				header: "Дата пол-я док-ов(пр)",
				dataIndex: 'ferrydocdate_str',
				width: getColumnWidth('ferrydocdate_str', 130),
				sortable: true
			},
			{
				header: "Оплата план КЛ",
				dataIndex: 'client_plandate_str',
				width: getColumnWidth('client_plandate_str', 90),
				sortable: false
			},
			{
				header: "Оплата план ПР",
				dataIndex: 'ferry_plandate_str',
				width: getColumnWidth('ferry_plandate_str', 90),
				sortable: false
			},
			{
				header: "Прибыль план",
				dataIndex: 'profit',
				width: getColumnWidth('profit'),
				sortable: true
			},
			{
				header: "Рентабельность %",
				dataIndex: 'profitpercent',
				width: getColumnWidth('profitpercent'),
				sortable: false
			},
			{
				header: "Прибыль факт",
				dataIndex: 'profitfact',
				width: getColumnWidth('profitfact'),
				sortable: true
			},
			{
				header: "Рентабельность факт %",
				dataIndex: 'profitabilityfact',
				width: getColumnWidth('profitabilityfact'),
				sortable: false
			}
		]
		this.grid = Ext.create('Ext.grid.Panel', {
			cls: 'grid-mark-dirty-disable',
			store: this.store,
			loadMask: true,
			columnLines: true,
			stateful: true,
			stateId: 'transp_grid__state',
			columns: RolesHelper.filterGridColumns(cols, this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS),
			viewConfig: {
				stripeRows: true
			},
			bbar: this.gridBbar,
			scrollTop: 0,
			listeners: {
				columnresize: function (headerCt, column, width) {
					if (column?.dataIndex) {
						const state = Ext.state.Manager.get(TRANSP_GRID_ALIAS) || { widths: {} }
						state.widths[column.dataIndex] = width
						Ext.state.Manager.set(TRANSP_GRID_ALIAS, state)
					}
				},
			},
			onVerticalScroll: function(event, target) {
				var owner = this.getScrollerOwner(),
					items = owner.query('tableview'),
					i = 0,
					len = items.length;

				for (; i < len; i++) {
					items[i].el.dom.scrollTop = target.scrollTop;
				}

				if (this.verticalScroller) {
					var el = this.verticalScroller.scrollEl,
					elDom = el && el.dom;

					this.scrollTop = elDom ? elDom.scrollTop : null;
				}
			},
		})

		this.grid.on('afterrender', function() {
			let hiddenColumns = localStorage.getItem(TRANSP_GRID_HIDDEN_COLS_ALIAS)
			if (hiddenColumns) {
				hiddenColumns = Ext.decode(hiddenColumns)
				Ext.each(this.grid.columns, function(column) {
					if (hiddenColumns.includes(column.dataIndex)) {
						column.hide()
					}
				})
			}

			this.grid.on('columnhide', function(grid, col) {
				const hiddenColumns = localStorage.getItem(TRANSP_GRID_HIDDEN_COLS_ALIAS) ?
					Ext.decode(localStorage.getItem(TRANSP_GRID_HIDDEN_COLS_ALIAS)) : []
				if (!hiddenColumns.includes(col.dataIndex)) {
					hiddenColumns.push(col.dataIndex)
				}

				localStorage.setItem(TRANSP_GRID_HIDDEN_COLS_ALIAS, Ext.encode(hiddenColumns))
			})

			this.grid.on('columnshow', function(grid, col) {
				const hiddenColumns = localStorage.getItem(TRANSP_GRID_HIDDEN_COLS_ALIAS) ?
					Ext.decode(localStorage.getItem(TRANSP_GRID_HIDDEN_COLS_ALIAS)) : []
				const index = hiddenColumns.indexOf(col.dataIndex);
				if (index !== -1) {
					hiddenColumns.splice(index, 1)
				}
				localStorage.setItem(TRANSP_GRID_HIDDEN_COLS_ALIAS, Ext.encode(hiddenColumns))
			})
		}, this)

		this.grid.getView().on('render', function(view) {
			view.tip = Ext.create('Ext.tip.ToolTip', {
				target: view.el,
				delegate: view.cellSelector,
				trackMouse: true,
				autoHide: false,
				minWidth: 300,
				autoWidth: true,
				autoHeight : true,
				listeners: {
					'beforeshow': {
                        fn: function (tip) {
                            let msg
                            const record = this.grid.getView().getRecord(tip.triggerElement.parentNode);
                            const dataIndex = this.grid.columns[tip.triggerElement.cellIndex].dataIndex; //+1 потому что checkboxcolumn

                            if (dataIndex === 'direction')
                                msg = record.get('directiontip');
                            else if (dataIndex === 'ferryman_name') {
                                const fio = record.get('ferrymanperson_fio')
                                const phone = record.get('ferrymanperson_phone')
                                let ferrymanpersonText = fio ? ' / ' + fio + ' ' : ''
                                if (phone) {
                                    ferrymanpersonText = `${ferrymanpersonText} ${phone}`
                                }

                                msg = record.get('ferrytip') + ferrymanpersonText
                            } else if (dataIndex === 'clientpaidvalue') {
                                msg = record.get('client_sns')
                            } else if (dataIndex === 'ferrypaidvalue') {
                                msg = record.get('ferry_sns')
                            } else if (dataIndex === 'client_name') {
								const clientCompanyName = record.get('client_name') ?? ''
								const fio = record.get('clientperson_fio')
								const phone = record.get('clientperson_phone')
								let clientPersonText = fio ? ' / ' + fio + ' ' : ''
								if (phone) {
									clientPersonText = `${clientPersonText} ${phone}`
								}

								msg = clientCompanyName + ' ' + clientPersonText
                            }
							else
								msg = Ext.get(tip.triggerElement).dom.childNodes[0].innerHTML;
								tip.update(msg.replace(/\n/g, '<br/>'))
						},
						scope: this
					},
				}
			})
		}, this)

		Ext.applyIf(config, {
			border: false,
			layout: 'fit',
			items: [
				this.grid
			]
		});

		kDesktop.transportation3.transportationsGridPanel.superclass.constructor.call(this, config);

		this.on('afterrender', function() {
			this.checkColumn.hide()
		}, this)
	},
	reCreateScroll: function() {
		var me = this.grid;
		if (me.verticalScroller) {
			me.mun(me.view.el, {
				mousewheel: me.onMouseWheel,
				scope: me
			});

			var i = 0;
			if (me.dockedItems && me.dockedItems.items && me.dockedItems.items.length) {
				var index = me.dockedItems.keys.indexOf(me.verticalScroller.id);
				if (index > -1) {
					me.dockedItems.keys.splice(index, 1);
				}

				while (i < me.dockedItems.items.length) {
					if (me.dockedItems.items[i].id === me.verticalScroller.id) {
						me.verticalScroller.destroy();
						me.dockedItems.items.splice(i, 1);
						break;
					} else {
						i++;
					}
				}

				delete me.dockedItems.map[me.verticalScroller.id];
			}
			delete me.verticalScroller;

			me.verticalScroller = Ext.create('Ext.grid.Scroller', {
				dock: 'right',
				store: me.store
			});
			me.mon(me.verticalScroller, {
				bodyscroll: me.onVerticalScroll,
				scope: me
			});

			me.addDocked(me.verticalScroller);
			me.verticalScroller.ensureDimension();

			me.mon(me.view.el, {
				mousewheel: me.onMouseWheel,
				scope: me
			});

			me.setScrollTop(me.scrollTop);
		}

		me.invalidateScroller();
		me.determineScrollbars();
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
		this.parent = config.parent;

		const TypesHelper = helpers.types
		const configData = config?.data ?? {}
		const clientConfig = config?.clientConfig ?? {}
		this.priv = TypesHelper.isObjectAndHasProps(configData?.priv) ? configData.priv : {}
		this.permissions = TypesHelper.isObjectAndHasProps(configData?.permissions) ? configData.permissions : {}
		this.uid = 'transportation-transportations';
		this.title = 'Грузоперевозки';
		this.closable = false;
		this.filterPanel = Ext.create('kDesktop.transportation3.transportationsFilterPanel', {
			region: 'north',
			height: 300,
			collapsible: true,
			animCollapse: false,
			border: false,
			ownerModule: this.ownerModule,
			parent: this,
			data: config.data,
			clientConfig: clientConfig,
		});

		this.gridPanel = Ext.create('kDesktop.transportation3.transportationsGridPanel', {
			region: 'center',
			id: 'transportation_transportationsGridPanel',
			ownerModule: this.ownerModule,
			parent: this,
			data: config.data,
			clientConfig: clientConfig,
		});

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

		const showRegistry = clientConfig?.showRegistersInContextMenu &&
			!RolesHelper.isOperationHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATION_OPERATION, RolesHelper.OP_REGISTRY_NAME)
		this.gridPanel.grid.on('itemdblclick', function(view, rec, item, index, eventObj, options) {
			this.onEditTransportation(rec.get('id'), 'edit', null, clientConfig);
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
							this.onEditTransportation(null, 'new', 0, clientConfig);
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
							this.onEditTransportation(null, 'new', 0, clientConfig);
						}
					},
					'-',
					{
						text: 'Редактировать',
						scope: this,
						handler: function (){
							this.onEditTransportation(id, 'edit', null, clientConfig);
						}
					},
				];

				if (this.priv && this.priv.transportation && this.priv.transportation.copy) items.push(
					'-',
					{
						text: 'Копировать',
						scope: this,
						handler: function (){
							this.onEditTransportation(id, 'copy', null, clientConfig);
						}
					}
				);

				items.push(
					'-',
					{
						text: 'Доп-заявка',
						scope: this,
						handler: function (){
							this.onEditTransportation(id, 'multi', null, clientConfig);
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
													this.gridPanel.gridBbar.doRefresh()
													this.ownerModule.closeTabById(id)
													helpers.transportation.onTransportationTabClose(id)
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

				if ( this.priv && this.priv.transportation && this.priv.transportation.makeCargoInsuranceRequest && !RolesHelper.isOperationHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATION_OPERATION, RolesHelper.OP_APPLICATION_FOR_CARGO_INSURANCE_NAME) ) items.push(
					'-',
					{
						text: 'Заявление на страхование груза',
						scope: this,
						handler: function (){
							Ext.MessageBox.confirm('Подтвердить?', 'Создать заявление на страхование груза?',
								function(btn){
									if(btn == 'yes') {
										this.ownerModule.app.doAjax({
											module: this.ownerModule.moduleId,
											method: 'makeCargoInsuranceRequest',
											id: rec.get('id')
										},
										function(res) {
										},
										this, this);
									}
								},
								this
							);
						}
					}
				);
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

		Ext.applyIf(config, {
			border: false,
			layout: 'border',
			items: [
				this.filterPanel,
				this.gridPanel
			]
		});

		kDesktop.transportation3.transportations.superclass.constructor.call(this, config);

		this.on('activate', function () {
			this.gridPanel.reCreateScroll();
		}, this);

		this.on('afterrender', function () {
			const state = this.gridPanel.grid.getState()
			if (state && state.columns && state.columns.length) for (var i = 0; i < state.columns.length; i++) {
				const col = state.columns[i];
				if (col && col.width && (col.width > 0) && this.gridPanel.grid.columns[i]) this.gridPanel.grid.columns[i].setWidth(col.width);
			}

			let tabs = helpers.transportation.getTabListLocalStorage()
			tabs = helpers.transportation.removeEmptyTabs(tabs)
			if (!TypesHelper.isArrayWithLength(tabs)) return false

			const promises = []
			const activeTab = tabs.find(tab => tab.active)
			const activeId = helpers.transportation.getTransportationIdByTab(activeTab)
			for (const tab of tabs) {
				const tid = helpers.transportation.getTransportationIdByTab(tab)
				if (!tid || tid.includes('new')) continue

				promises.push(new Promise((resolve) => {
					this.onEditTransportation(tid, 'edit', null, clientConfig, resolve, false)
				}))
			}

			Promise.all(promises).then(() => {
				const activeTab = tabs.find(tab => tab.active)
				if (!activeTab) return false

				const activeId = helpers.transportation.getTransportationIdByTab(activeTab)
				if (!activeId) return false

				const tabToActivate = this.ownerModule.getTabItem(activeId)
				if (!tabToActivate) return false

				this.ownerModule.setActiveTab(tabToActivate)
			})
		}, this)
	},
	enableCreateBill1cMode: function() {
		this.createBill1cMode = true;
		this.gridPanel.checkColumn.show();

		this.gridPanel.grid.getHorizontalScroller().setScrollLeft(0);
	},

	disableCreateBill1cMode: function() {
		this.createBill1cMode = false;
		this.createBill1cModeClientId = 0;
		this.createBill1cModeClientNds = 0;
		this.createBill1cModeClientCurrency = '';

		this.gridPanel.checkColumn.hide();
		this.createBill1cModeUncheckColumn();
	},

	сheckOnlyOnePageCount: function() {
		if (this.gridPanel.store.getTotalCount() > this.gridPanel.store.pageSize) {
			Ext.MessageBox.alert('', 'Воспользуйтесь фильтром или выберите большее количество записей на странице, чтобы записи помещались на одной странице.');
			return false;
		}

		return true;
	},

	onCheckColumnBeforeClick: function(col, index, record, checked, eventObj) {
		if (this.сheckOnlyOnePageCount() === false) return false;

		if (checked && (this.gridPanel.checkColumn.checkedCount > 0)) {
			if (record.get('client') != this.createBill1cModeClientId) return false;
			if (record.get('clientnds') != this.createBill1cModeClientNds) return false;
			if (record.get('client_currency') != this.createBill1cModeClientCurrency) return false;
		}

		return true;
	},

	onCheckColumnClick: function(col, index, record, checked, eventObj) {
		if (this.gridPanel.checkColumn.checkedCount == 0) {
			this.createBill1cModeClientId = 0;
			this.createBill1cModeClientNds = 0;
			this.createBill1cModeClientCurrency = '';
		}
		else if (this.gridPanel.checkColumn.checkedCount == 1) {
			this.createBill1cModeClientId = record.get('client');
			this.createBill1cModeClientNds = record.get('clientnds');
			this.createBill1cModeClientCurrency = record.get('client_currency');
		}
	},

	onCheckColumnHeaderClick: function(col, index, record, eventObj) {
		if ((!this.gridPanel.checkColumn.headerChecked) && (this.сheckOnlyOnePageCount() === false)) return false;

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
		}
	},

	createBill1cModeUncheckColumn: function() {
		if (this.gridPanel.store.getCount()) for(var i=0; i<this.gridPanel.store.getCount(); i++) {
			var record = this.gridPanel.store.getAt(i);
			if (record.get('selectionModelChecked') === true) record.set('selectionModelChecked', false);
		}
		this.createBill1cModeClientId = 0;
		this.createBill1cModeClientNds = 0;
		this.createBill1cModeClientCurrency = '';
		this.gridPanel.checkColumn.checkedCount = 0;
	},

	сreateBill1c2: function() {
		if (this.gridPanel.checkColumn.checkedCount == 0) {
			Ext.MessageBox.alert('Ошибка', 'Не выбрано ни одной записи');
			return;
		}

		var data = [];
		for(var i=0; i<this.gridPanel.store.getCount(); i++) {
			var rec = this.gridPanel.store.getAt(i);

			if (rec && (rec.get('selectionModelChecked') === true)) data.push( rec.get('id') );
		}

		Ext.create('kDesktop.transportation3.createBill1cWnd2', { ownerModule: this.ownerModule, parent: this, data: data }).show();
	},
	onEditTransportation: function(oid, mode, type, clientConfig, resolve, updateLsTabs = true, isActive = false) {
		if (updateLsTabs) {
			if (oid !== 'transportation-transportations') {
				helpers.transportation.onTransportationTabOpen(oid) // manage transportations tab list in LocalStorage
				const existingTab = this.ownerModule.items.findBy(function (item) {
					return item.oid === oid
				})

				if (existingTab) {
					this.ownerModule.setActiveTab(existingTab)
					if (typeof resolve === 'function') {
						resolve()
					}
					return false
				}
			}
		}

		let tab = null
		let uid = ''
		if ((mode === 'new') || (mode === 'copy') || (mode === 'multi')) {
			uid = 'new'
		} else {
			uid = oid
			tab = this.ownerModule.getTabItem(uid)
		}

		if (!tab) {
			this.ownerModule.app.doAjax({
				module: this.ownerModule.moduleId,
				method: 'transpData',
				mode: mode,
				id: (mode === 'new') ? 0 : oid
			},
			function(res) {
				if ((mode === 'copy') || (mode === 'multi')) {
					mode = 'new'
					oid = null
				}

				tab = Ext.create('kDesktop.transportation3.transpEdit', {
					ownerModule: this.ownerModule,
					parent: this,
					uid: uid,
					oid: oid,
					data: res,
					mode: mode,
					permissions: this.permissions,
					clientConfig: clientConfig,
				})

				this.ownerModule.add(tab)
				this.ownerModule.setActiveTab(tab)

				if (typeof resolve === 'function') {
					resolve()
				}
			},
				this, this)
		} else {
			this.ownerModule.setActiveTab(tab)
			if (typeof resolve === 'function') {
				resolve()
			}
		}
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

Ext.define('kDesktop.transportation3.transportationsUnloadCheckWnd', {
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
			this.onEditTransportation(rec.get('id'), 'edit', null, this.clientConfig))

		this.gridPanel.grid.on('containercontextmenu', function(view, eventObj){
			eventObj.stopEvent();
		}, this);
		this.gridPanel.grid.on('itemcontextmenu',function(view, rec, node, index, eventObj) {
			const items = [
				{
					text: 'Редактировать',
					scope: this,
					handler: () => this.onEditTransportation(rec.get('id'), 'edit', null, this.clientConfig)
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
	onEditTransportation: function(oid, mode, type, clientConfig = {}) {
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
});

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

		if (this.mode == 'new')
			this.title = 'Новая грузоперевозка';
		else
			this.title = 'Грузоперевозка ' + this.data.data.idstr;

		this.generalPanel = Ext.create('kDesktop.transportation3.transpEdit.generalPanel', {
			title: 'Общие',
			hidden: RolesHelper.isTransportationTabHidden(this.permissions, RolesHelper.TAB_MAIN_NAME),
			ownerModule: this.ownerModule,
			parent: this,
			clientConfig: this.clientConfig
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
			parent: this
		});

		this.ferryPanel = Ext.create('kDesktop.transportation3.transpEdit.ferryPanel', {
			title: 'Перевозчик',
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
			parent: this
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

		this.surveerPanel = Ext.create('kDesktop.transportation3.transpEdit.surveerPanel', {
			title: 'Сюрвейер',
			hidden: RolesHelper.isTransportationTabHidden(this.permissions, RolesHelper.TAB_SURVEER_NAME),
			ownerModule: this.ownerModule,
			parent: this,
		});

		this.curDiffPanel = Ext.create('kDesktop.transportation3.transpEdit.curDiffPanel', {
			title: 'Расчеты',
			hidden: RolesHelper.isTransportationTabHidden(this.permissions, RolesHelper.TAB_CALCULATIONS_NAME),
			ownerModule: this.ownerModule,
			parent: this
		});

		var items = [
			this.generalPanel,
			this.clientPanel,
			this.loadUnloadPanel,
			this.ferryPanel,
			this.financePanel,
			this.docPanel,
			this.finePanel,
			this.reportPanel,
			this.surveerPanel,
			this.curDiffPanel
		];

		if (this.priv && this.priv.transportation && this.priv.transportation.viewLog) {
			this.logStore = Ext.create('Ext.data.Store', {
				pageSize: 40,
				root: 'items',
				idProperty: 'id',
				remoteSort: true,
				autoLoad: true,
				fields: [
					'date_str',
					'user_login',
					'log'
				],
				proxy: {
					actionMethods: 'POST',
					type: 'ajax',
					url: this.ownerModule.app.connectUrl,
					extraParams: {
						module: this.ownerModule.moduleId,
						method: 'logGrid'
					},
					reader: {
						type: 'json',
						root: 'items',
						totalProperty: 'totalCount'
					}
				}
			});
			this.logStore.on('beforeload', function(){
				this.logStore.proxy.extraParams.id = this.oid;
			},this);

			this.logGrid = Ext.create('Ext.grid.Panel', {
				store: this.logStore,
				loadMask: true,
				columnLines: true,
				columns:[
					{
						header: "Дата",
						dataIndex: 'date_str',
						width: 120,
						sortable: false
					},
					{
						header: "Пользователь",
						dataIndex: 'user_login',
						width: 150,
						sortable: false
					},
					{
						header: "Лог",
						dataIndex: 'log',
						width: 500,
						sortable: false,
						renderer: function columnWrap(val){
							val = val === undefined || val === null ? '' : val.replace(/\r\n/g, '<br/>');
							return '<div style="white-space: pre-wrap; word-wrap: break-word !important;">'+ val +'</div>';
						}

					}
				],
				viewConfig: {
					stripeRows: true
				},
				dockedItems: [{
					xtype: 'pagingtoolbar',
					store: this.logStore,
					dock: 'bottom',
					displayInfo: true,
					displayMsg: 'Записи {0} - {1} из {2}',
					emptyMsg: "Нет записей"
				}]
			});

			items.push({
				title: 'Лог',
				hidden: RolesHelper.isTransportationTabHidden(this.permissions, RolesHelper.TAB_LOG_NAME),
				frame: false,
				border: false,
				layout: 'fit',
				items: [
					this.logGrid
				]
			});
		}

		this.tabPanel = Ext.create('Ext.tab.Panel', {
			border: true,
			layout: 'fit',
			activeItem: 0,
			items: items,
			bbar: [
				'->',
				{
					text: 'Cохранить',
					iconCls: 'ok-icon',
					scope: this,
					hidden: (this.data.allowed && (this.data.allowed.allowedCount === 0)) ? true : false,
					handler: function(){
						this.save();
					}
				},
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

		this.mainForm = Ext.create('Ext.form.Panel', {
			width: 920,
			layout: 'fit',
			border: false,
			frame: false,
			items: [
				this.tabPanel
			]
		});

		this.sdoctplMenu = Ext.create('Ext.menu.Menu', { items: [] });

		Ext.applyIf(config, {
			border: false,
			tbar: [
				{
					text: 'Документы',
					menu: this.sdoctplMenu
				}
			],
			layout: {
				type: 'hbox',
				align: 'stretch'
			},
			items: [
				this.mainForm
			]
		});
		kDesktop.transportation3.transpEdit.superclass.constructor.call(this, config);

		this.on('afterrender', function() {
			if (this.data) this.loadData();
		}, this);

		this.on('destroy', function(o, eOpts) {
			if (this.fromUnloadCheckWnd) this.parent.close();
		}, this);

		const onBeforeClose = () => {
			const oid = this.oid ?? 'new'
			helpers.transportation.onTransportationTabClose(oid)
		}
		this.on('beforeclose', onBeforeClose, this);
	},
	loadData: function() {
		if (this.data && this.data.sdoctpl) {
			this.sdoctplMenu.removeAll();
			for(var i in this.data.sdoctpl) {
				if (!this.data.sdoctpl.hasOwnProperty(i)) continue;
				this.sdoctplMenu.add({
					text: this.data.sdoctpl[i].name,
					url: '?m=sdoctpl&p=createDoc&did='+this.data.sdoctpl[i].id+'&id='+this.oid+'&from=transp',
					handler: function (){
						window.open(this.url, "download");
					}
				});
			}
		}

		const RolesHelper = helpers.roles
		if (this.data.userList) this.generalPanel.logistCmb.store.loadData(this.data.userList);
		if (this.data.managerList) this.generalPanel.managerCmb.store.loadData(this.data.managerList);
		if (this.data.statusDict) this.generalPanel.statusCmb.store.loadData(this.data.statusDict);

		if (!RolesHelper.isFieldHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS, 'client_name') && this?.data?.clientList) {
			this.clientPanel.clientCmb.store.loadData(this.data.clientList);
		}
		const transportTypeList = this.clientConfig?.transportTypeList ?? {}
		if (helpers.types.isObjectAndHasProps(transportTypeList)) {
			this.clientPanel.typetsCmb.store.loadData(helpers.data.convertObjectToStoreData(transportTypeList))
		}

		if (this.data.regionDict) this.clientPanel.regionCmb.store.loadData(this.data.regionDict);

		if (!RolesHelper.isFieldHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS, 'client_sns')) {
			this.clientPanel.clientSnsFld.setValue('Платежный баланс: ' + this._s(this.data.data.client_sns))
		}

		if (!RolesHelper.isFieldHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS, 'client_accountant')) {
			this.clientPanel.clientAccountantFld.setValue('Бухгалтер: ' + this._s(this.data.data.client_accountant));
		}

		if (!RolesHelper.isFieldHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS, 'ferryman_name') && this.data.ferrymanList) {
			this.ferryPanel.ferrymanCmb.store.loadData(this.data.ferrymanList);
		}

		if (!RolesHelper.isFieldHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS, 'ferry_sns')) {
			this.ferryPanel.ferrySnsFld.setValue('Платежный баланс: ' + this._s(this?.data?.data?.ferry_sns));
		}
		if (!RolesHelper.isFieldHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS, 'clientdocdelivery') && this.data.clientDocdeliveryDict) {
			this.docPanel.mainPanel.clientDocdeliveryCmb.store.loadData(this.data.clientDocdeliveryDict);
		}

		if (this.data.survDocTypeDict) this.surveerPanel.docTypeStore.loadData(this.data.survDocTypeDict);
		if (this.data.ferrymanList) this.surveerPanel.mainPanel.survCrmCompanyCmb.store.loadData(this.data.ferrymanList);

		if (this.data.loadGrid) this.loadUnloadPanel.loadStore.loadData(this.data.loadGrid);
		if (this.data.unloadGrid) this.loadUnloadPanel.unloadStore.loadData(this.data.unloadGrid);

		if (this.data.data) {
			this.multimodal_id = this.data.data.multimodal_id;
			this.mainForm.getForm().setValues(this.data.data);
		}

		if (this.mode == 'new') this.data.data = {};
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

		if (!this.surveerPanel.getForm().isValid()) {
			this.tabPanel.setActiveTab( this.surveerPanel );
			return false;
		}

		Ext.MessageBox.confirm('Сохранение', 'Вы уверены что хотите сохранить эту запись?',
			function(btn){
				if(btn == 'yes') {
					var data = {};
					data.data = this.ownerModule.app.getFormValues(this.mainForm);
					data.data.multimodal_id = this.multimodal_id;
					data.origData = (this.data && this.data.data) ? this.data.data : null;

					data.loadGridDeleted = this.loadUnloadPanel.loadStoreDeleted;
					data.unloadGridDeleted = this.loadUnloadPanel.unloadStoreDeleted;

					data.loadGridOrig = this.data.loadGrid;
					data.unloadGridOrig = this.data.unloadGrid;

					data.loadGrid = [];
					data.unloadGrid = [];

					var i, rec;
					for(i = 0; i < this.loadUnloadPanel.loadStore.getCount(); i++) {
						rec = this.loadUnloadPanel.loadStore.getAt(i);
						data.loadGrid.push({
							id: rec.get('id'),
							extid: rec.get('extid'),
							date: rec.get('date'),
							time: rec.get('time'),
							comment: rec.get('comment'),
							address: rec.get('address'),
							contacts: rec.get('contacts'),
							dirty: (rec.dirty && (rec.dirty === true)) ? 1 : 0
						});
					}
					for(i = 0; i < this.loadUnloadPanel.unloadStore.getCount(); i++) {
						rec = this.loadUnloadPanel.unloadStore.getAt(i);
						data.unloadGrid.push({
							id: rec.get('id'),
							extid: rec.get('extid'),
							date: rec.get('date'),
							time: rec.get('time'),
							comment: rec.get('comment'),
							address: rec.get('address'),
							contacts: rec.get('contacts'),
							dirty: (rec.dirty && (rec.dirty === true)) ? 1 : 0
						});
					}

					this.ownerModule.app.doAjax({
						module: this.ownerModule.moduleId,
						method: 'transpSave',
						mode: this.mode,
						id: (this.mode === 'new') ? 0 : this.oid,
						data: Ext.encode(data)
					},
					function(res) {
						const tid = res?.id ?? null

						if (this.fromUnloadCheckWnd) {
							this.parent.parent.gridPanel.store.load();
							this.close();
						}
						else {
							const tab = this.ownerModule.getTabItem('transportation-transportations')
							if (tab) {
								this.parent.gridPanel.store.load()
							}

							if (tid && this.mode === 'new') {
								this.mode = 'edit'

								this.oid = tid
								this.uid = tid
								helpers.transportation.changeNewTransportationTabId(tid.toString())

								this.financePanel.gridStore.proxy.extraParams.tid = tid
								this.docPanel.docGridStore.proxy.extraParams.tid = tid;
								this.surveerPanel.docGridStore.proxy.extraParams.tid = tid;
								this.reportPanel.store.proxy.extraParams.tid = tid;
							}

							if (res.data) {
								this.data = res.data;
								this.loadData()

								this.title = 'Грузоперевозка ' + this.data.data.idstr;
								this.setTitle(this.title);
							}

							if (this.logStore) this.logStore.load();
						}
					},
					this, this);
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
// 			autoHeight: true,
			modal: true,
// 			plain: true,
			border: false,
			items: [
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
	constructor: function(config) {
		config = config || {};
		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.priv = this.parent.priv;
		this.permissions = config?.permissions ?? {}
		this.clientContractStore = Ext.create('Ext.data.Store', {
			pageSize: 40,
			root: 'items',
			idProperty: 'id',
			remoteSort: true,
			autoLoad: true,
			fields: [
				'id',
				{name: 'sl', type: 'int'},
				'name',
				'currency',
				'rate'
			],
			proxy: {
				actionMethods: 'POST',
				type: 'ajax',
				url: this.ownerModule.app.connectUrl,
				extraParams: {
					module: this.ownerModule.moduleId,
					method: 'clientContractStore',
					id: this.parent.data.data.client,
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
			}],
			listeners: {
				load: {
					fn: function(store, records, successful, operation, eOpts) {
						this.procContractLimit();
					},
					scope: this
				}
			}
		});
		this.clientPersonStore = Ext.create('Ext.data.Store', {
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
					method: 'clientPersonStore',
					id: this.parent.data.data.client,
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
		const RolesHelper = helpers.roles
		const clientRequestFieldsFiltered = RolesHelper.filterFormFields([
			{
				xtype: 'textfield',
				name: 'client_request_no',
				alias: 'client_request',
				fieldLabel: '№ заявки клиент',
				labelSeparator: '',
				labelWidth: 170,
				width: 420,
			},
			{
				xtype: 'datefield',
				name: 'client_request_date',
				alias: 'client_request',
				width: 100,
				allowBlank: true,
				format: 'd.m.Y',
				editable: false,
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

		Ext.applyIf(config, {
			closable: false,
			autoScroll: true,
			frame: true,
			defaults: { xtype: 'container', layout: { type: 'hbox'} },
			items: [
				...RolesHelper.filterFormFields([
					{
						items: [
							{
								xtype: 'combobox',
								name: 'client',
								alias: 'client_name',
								ref: 'clientCmb',
								width: 420,
								fieldLabel: 'Клиент',
								labelSeparator: '',
								labelWidth: 170,
								queryMode: 'local',
								displayField: 'value',
								valueField: 'key',
								store: Ext.create('Ext.data.JsonStore', {fields: ['key', 'value'], idProperty: 'key'}),
								listeners: {
									select: {
										fn: function (cmb) {
											var cmb2 = this.clientContractCmb;
											cmb2.enable();
											cmb2.reset();
											cmb2.store.removeAll();
											cmb2.lastQuery = null;
											cmb2.setValue();
											this.clientContractStore.proxy.extraParams.id = cmb.getValue();
											this.clientContractStore.load();
											cmb2.bindStore(this.clientContractStore);

											var cmb3 = this.clientPersonCmb;
											cmb3.enable();
											cmb3.reset();
											cmb3.store.removeAll();
											cmb3.lastQuery = null;
											cmb3.setValue();
											this.clientPersonStore.proxy.extraParams.id = cmb.getValue();
											this.clientPersonStore.load();
											cmb3.bindStore(this.clientPersonStore);
										},
										scope: this
									}
								}
							},
							{
								xtype: 'combobox',
								name: 'clientcontract',
								ref: 'clientContractCmb',
								width: 450,
								queryMode: 'remote',
								displayField: 'name',
								valueField: 'id',
								store: this.clientContractStore,
								editable: false,
								listConfig: {
									getInnerTpl: function () {
										return '<div style="{[values["sl"] > 90 ? "color:red; font-weight: bold" : ""]}">' +
											'{name}' +
											'</div>';
									}
								},
								listeners: {
									select: {
										fn: function (cmb, rcrd, indx) {
											var contract = rcrd[0];
											var cur = contract.get('currency');
											this.clientCurrencyFld.setValue(cur);

											if (cur == 'RUR')
												this.clientCurrencyRateFld.setValue('1');
											else
												this.clientCurrencyRateFld.setValue(contract.get('rate'));

											this.currencyValue();
										},
										scope: this
									},
									change: {
										fn: function () {
											this.procContractLimit();
										},
										scope: this
									}
								}
							}
						]
					},
					{
						items: [
							{
								xtype: 'combobox',
								name: 'clientperson',
								ref: 'clientPersonCmb',
								value: this.parent.data.data.clientperson,
								fieldLabel: 'Контактное лицо',
								labelSeparator: '',
								labelWidth: 170,
								width: 870,
								queryMode: 'remote',
								displayField: 'name',
								valueField: 'id',
								store: this.clientPersonStore,
								editable: false,
								allowBlank: false
							}
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
						{xtype: 'displayfield', width: 45, value: 'Тип ТС'},
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
							width: 685,
							height: 22
						}
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 170, value: 'Маршрут (откуда/куда)'},
						{
							xtype: 'textfield',
							name: 'clientfromplace',
							width: 350,
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
							width: 350,
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
							store: Ext.create('Ext.data.ArrayStore', {
								fields: [
									'id',
									'name'
								],
								data: [
									[20, 'Внутрироссийская НДС 20%'],
									[0, 'Международная НДС 0%']
								]
							}),
							listeners: {
								select: function(cmb, rcrd, indx) {
									this.parent.ferryNds();
								},
								scope: this
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
								style: {
									marginRight: '40px'
								}
							},
							{
								xtype: 'textfield',
								name: 'clientotherchargestarget',
								width: 560,
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
								width: 200,
								fieldLabel: 'Курс план',
								labelWidth: 70,
								labelSeparator: '',
								value: '0',
								minValue: 0,
								decimalPrecision: 4,
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
								name: 'clientinvoice',
								width: 300
							},
							{
								xtype: 'textfield',
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
	currencyValue: function() {
		if(this.clientCurrencyRateFld && this.clientCurrencySumFld && this.clientCurrencyTotalFld){
			var value = this.clientCurrencyRateFld.getValue() * this.clientCurrencySumFld.getValue();
			if (this.clientCurrencyFld.getValue() == 'KZT') value = Number(value / 100).toFixed(4);
			this.clientCurrencyTotalFld.setValue(value);
		}
	},

	procContractLimit: function() {
		if (this.clientContractCmb) {
			this.clientContractCmb.removeCls('combobox-bold-red');

			var rec = this.clientContractCmb.getValue();
			if (rec && (rec > 0)) rec = this.clientContractCmb.store.findRecord('id', rec);
			if (rec && (rec.get('sl') > 90)) this.clientContractCmb.addCls('combobox-bold-red');
		}
	}
});

Ext.define('kDesktop.transportation3.transpEdit.loadUnloadPanel', {
	extend: 'Ext.form.Panel',
	constructor: function(config) {
		config = config || {};

		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.priv = this.parent.priv;

		this.loadStoreDeleted = [];
		this.loadStore = Ext.create('Ext.data.ArrayStore', {
			fields: [
				{name: 'id', type: 'int'},
				{name: 'extid', type: 'int'},
				{name: 'date', type: 'date'},
				{name: 'time', type: 'date'},
				'comment',
				'address',
				'contacts'
			]
		});

		this.loadGridEditPlugin = Ext.create('Ext.grid.plugin.CellEditing', {
			clicksToEdit: 1
		});

		this.loadGrid = Ext.create('Ext.grid.Panel', {
			store: this.loadStore,
			height: 290,
			loadMask: true,
			columnLines: true,
			columns:[
				{
					header: "Загрузка",
					dataIndex: 'date',
					width: 100,
					sortable: false,
					renderer: function (value){
						return value ? Ext.Date.dateFormat(value, 'd.m.Y') : '';
					},
					field: {
						xtype: 'datefield',
						format: 'd.m.Y',
						editable: false,
						startDay: 1
					}
				},
				{
					header: "Время",
					dataIndex: 'time',
					width: 100,
					sortable: false,
					renderer: function (value) {
						return value ? Ext.Date.dateFormat(value, 'H:i') : '';

		// 				if (!value) return value;
		// 				if (value instanceof Date) return Ext.Date.dateFormat(value, 'H:i');
		// 				value = new Date('0001-01-01 '+value);
		// 				return Ext.Date.dateFormat(value, 'H:i');
					},
					field: {
						xtype: 'timefield',
						format: 'H:i',
						editable: true,
						increment: 15
					}
				},
				{
					header: "Примечание",
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
					width: 280,
					sortable: true,
					field: {
						allowBlank: true
					}
				},
				{
					header: "Контактное лицо",
					dataIndex: 'contacts',
					width: 200,
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
		});
		this.loadGrid.on('containercontextmenu', function(view, eventObj){
			var _contextMenu = Ext.create('Ext.menu.Menu', {
				items: [
					{
						text: 'Добавить',
						scope: this,
						handler: function (){
							this.addLoadUnload(this.loadStore);
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
							this.addLoadUnload(this.loadStore);
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
		this.loadGrid.getView().on('render', function(view) {
			view.tip = Ext.create('Ext.tip.ToolTip', {
				target: view.el,
				delegate: view.cellSelector,
				trackMouse: true,
				autoHide: false,
				listeners: {
					'beforeshow': {
						fn: function(tip){
							var msg;
							var record = this.loadGrid.getView().getRecord(tip.triggerElement.parentNode);
							msg = Ext.get(tip.triggerElement).dom.childNodes[0].innerHTML;
							tip.update(msg.replace(/\n/g, '<br/>'));
						},
						scope: this
					}
				}
			});
		}, this);

		this.unloadStoreDeleted = [];
		this.unloadStore = Ext.create('Ext.data.ArrayStore', {
			fields: [
				{name: 'id', type: 'int'},
				{name: 'extid', type: 'int'},
				{name: 'date', type: 'date'},
				{name: 'time', type: 'date'},
				'comment',
				'address',
				'contacts'
			]
		});

		this.unloadGridEditPlugin = Ext.create('Ext.grid.plugin.CellEditing', {
			clicksToEdit: 1
		});

		this.unloadGrid = Ext.create('Ext.grid.Panel', {
			store: this.unloadStore,
			height: 290,
			loadMask: true,
			columnLines: true,
			columns:[
				{
					header: "Выгрузка",
					dataIndex: 'date',
					width: 100,
					sortable: false,
					renderer: function (value){
						return value ? Ext.Date.dateFormat(value, 'd.m.Y') : '';
					},
					field: {
						xtype: 'datefield',
						format: 'd.m.Y',
						editable: false,
						startDay: 1
					}
				},
				{
					header: "Время",
					dataIndex: 'time',
					width: 100,
					sortable: false,
					renderer: function (value) {
						return value ? Ext.Date.dateFormat(value, 'H:i') : '';

		// 				if (!value) return value;
		// 				if (value instanceof Date) return Ext.Date.dateFormat(value, 'H:i');
		// 				value = new Date('0001-01-01 '+value);
		// 				return Ext.Date.dateFormat(value, 'H:i');
					},
					field: {
						xtype: 'timefield',
						format: 'H:i',
						editable: true,
						increment: 15
					}
				},
				{
					header: "Примечание",
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
					width: 280,
					sortable: true,
					field: {
						allowBlank: true
					}
				},
				{
					header: "Контактное лицо",
					dataIndex: 'contacts',
					width: 200,
					sortable: true,
					field: {
						allowBlank: true
					}
				}
			],
			viewConfig: {
				stripeRows: true
			},
			plugins: [ this.unloadGridEditPlugin ],
			selModel: {
				selType: 'cellmodel'
			}
		});
		this.unloadGrid.on('containercontextmenu', function(view, eventObj){
			var _contextMenu = Ext.create('Ext.menu.Menu', {
				items: [
					{
						text: 'Добавить',
						scope: this,
						handler: function (){
							this.addLoadUnload(this.unloadStore);
						}
					}
				]
			});
			_contextMenu.showAt(eventObj.getXY());
			eventObj.stopEvent();
		}, this);
		this.unloadGrid.on('itemcontextmenu',function(view, rec, node, index, eventObj) {
			var _contextMenu = Ext.create('Ext.menu.Menu', {
				items: [
					{
						text: 'Добавить',
						scope: this,
						handler: function (){
							this.addLoadUnload(this.unloadStore);
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
		this.unloadGrid.getView().on('render', function(view) {
			view.tip = Ext.create('Ext.tip.ToolTip', {
				target: view.el,
				delegate: view.cellSelector,
				trackMouse: true,
				autoHide: false,
				listeners: {
					'beforeshow': {
						fn: function(tip){
							var msg;
							var record = this.unloadGrid.getView().getRecord(tip.triggerElement.parentNode);
							msg = Ext.get(tip.triggerElement).dom.childNodes[0].innerHTML;
							tip.update(msg.replace(/\n/g, '<br/>'));
						},
						scope: this
					}
				}
			});
		}, this);

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
						{xtype: 'displayfield', width: 170, value: 'Дата пересечения границы'},
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
						{xtype: 'displayfield', width: 170, value: 'Погран переход'},
						{
							xtype: 'textfield',
							name: 'bordercross',
							width: 700
						}
					]
				}
			]
		});

		kDesktop.transportation3.transpEdit.loadUnloadPanel.superclass.constructor.call(this, config);

 		this.ownerModule.app.createReference(this);
	},

	addLoadUnload: function(store) {
		var maxId = 0;
		if (store.getCount() > 0) {
			var maxId = store.getAt(0).get('id');
			store.each(function(rec) {
				maxId = Math.max(maxId, rec.get('id'));
			});
		}

		maxId++;
		var newrec = new store.model({id: maxId, extid: 0});
		newrec.dirty = true;
		newrec.phantom = true;
		store.add(newrec);
	}
});

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
// 		this.grid.on('containercontextmenu', function(view, eventObj){
// 			var _contextMenu = Ext.create('Ext.menu.Menu', {
// 				items: [
// 					{
// 						text: 'Добавить',
// 						iconCls: 'add-icon',
// 						scope: this,
// 						handler: function () {
// 							this.edit('new', null);
// 						}
// 					}
// 				]
// 			});
// 			_contextMenu.showAt(eventObj.getXY());
// 			eventObj.stopEvent();
//
//
// 		}, this);
// 		this.grid.on('itemcontextmenu',function(view, rec, node, index, eventObj) {
// 			var _contextMenu = Ext.create('Ext.menu.Menu', {
// 				items: [
// 					{
// 						text: 'Документы',
// 						scope: this,
// 						handler: function () {
// 							Ext.create('kDesktop.transportation3.transpEdit.ferryPanel.carDocWnd', {
// 								ownerModule: this.ownerModule,
// 								parent: this,
// 								oid: rec.get('id')
// 							}).show();
// 						}
// 					},'-',
// 					{
// 						text: 'Добавить',
// 						iconCls: 'add-icon',
// 						scope: this,
// 						handler: function () {
// 							this.edit('new', null);
// 						}
// 					},
// 					{
// 						text: 'Редактировать',
// 						iconCls: 'edit-icon',
// 						scope: this,
// 						handler: function (){
// 							this.edit('edit', rec);
// 						}
// 					}
// 				]
// 			});
// 			_contextMenu.showAt(eventObj.getXY());
// 			eventObj.stopEvent();
//
// 		}, this);

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
	constructor: function(config) {
		config = config || {};
		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.priv = this.parent.priv;
		this.permissions = config?.permissions ?? {}
		this.clientConfig = config?.clientConfig ?? {}

		this.addEvents('updatecarliststore')
		this.on('updatecarliststore', this.onUpdateCarListStore, this)

		this.ferryContractStore = Ext.create('Ext.data.Store', {
			pageSize: 40,
			root: 'items',
			idProperty: 'id',
			remoteSort: true,
			autoLoad: true,
			fields: [
				'id',
				{name: 'sl', type: 'int'},
				'name',
				'currency',
				'rate',
				'payby',
				'paydelay'
			],
			proxy: {
				actionMethods: 'POST',
				type: 'ajax',
				url: this.ownerModule.app.connectUrl,
				extraParams: {
					module: this.ownerModule.moduleId,
					method: 'ferryContractStore',
					id: this.parent.data.data.ferryman,
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
			}],
			listeners: {
				load: {
					fn: function(store, records, successful, operation, eOpts) {
						this.procContractLimit();
					},
					scope: this
				}
			}
		});
		this.ferryPersonStore = Ext.create('Ext.data.Store', {
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
					method: 'ferryPersonStore',
					id: this.parent.data.data.ferryman,
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

		const RolesHelper = helpers.roles
		this.ferryCarsStore = helpers.ferrycars.createFerryCarsStore(
			this.permissions,
			this.parent?.data?.data?.ferryman ?? null
		)
		this.ferryCarsStore.on('load', function () {
			this.ferrymancarsBtn.setText("Список машин (" + this.ferryCarsStore.getTotalCount() + ")")
		}, this)

		const ferryCarIsShowed = !RolesHelper.isFieldHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS, 'ferrycar')
		const ferrycarPpIsShowed = !RolesHelper.isFieldHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS, 'ferrycarpp')
		const typetsIsShowed = !RolesHelper.isFieldHidden(this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS, 'typets')

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
								xtype: 'combobox',
								name: 'ferryman',
								alias: 'ferryman_name',
								ref: 'ferrymanCmb',
								fieldLabel: 'Перевозчик',
								labelSeparator: '',
								width: 475,
								labelWidth: 165,
								queryMode: 'local',
								displayField: 'value',
								valueField: 'key',
								store: Ext.create('Ext.data.JsonStore', {
									fields: ['key', 'value', 'nds'],
									idProperty: 'key'
								}),
								listeners: {
									select: {
										fn: function (cmb, rcrd, indx) {
											this.ferryCarsStore.proxy.extraParams.id = cmb.getValue();
											this.ferryCarsStore.load();

											var cmb2 = this.ferryContractCmb;
											cmb2.enable();
											cmb2.reset();
											cmb2.store.removeAll();
											cmb2.lastQuery = null;
											cmb2.setValue();
											this.ferryContractStore.proxy.extraParams.id = cmb.getValue();
											this.ferryContractStore.load();
											cmb2.bindStore(this.ferryContractStore);

											var cmb3 = this.ferryPersonCmb;
											cmb3.enable();
											cmb3.reset();
											cmb3.store.removeAll();
											cmb3.lastQuery = null;
											cmb3.setValue();
											this.ferryPersonStore.proxy.extraParams.id = cmb.getValue();
											this.ferryPersonStore.load();
											cmb3.bindStore(this.ferryPersonStore);

											this.parent.ferryNds();
										},
										scope: this
									}
								}
							},
							{
								xtype: 'button',
								width: 220,
								alias: 'ferryman_name',
								ref: 'ferrymancarsBtn',
								text: "Список машин",
								scope: this,
								handler: () => {
									// this.ferryCarsStore = helpers.ferrycars.createFerryCarsStore(
									// 	this?.permissions,
									// 	this.ferryCarsStore?.proxy?.extraParams?.id ?? null
									// )
									// this.ferryCarsStore.on('load', function () {
									// 	this.ferrymancarsBtn.setText("Список машин (" + this.ferryCarsStore.getTotalCount() + ")")
									// }, this)
									//
									Ext.create('ferryCarsListModal', {
										parent: this,
										permissions: this?.permissions ?? {},
										clientConfig: this?.clientConfig ?? {},
										ferryCarsStore: this?.ferryCarsStore ?? [],
										ferryman: this.ferrymanCmb.getValue()
									}).show()
								}
							},
							{xtype: 'displayfield', width: 10, value: '&nbsp;', alias: 'ferryman_name',},
							{
								xtype: 'displayfield',
								alias: 'ferryman_name',
								value: (this.parent.data.data.ferrycar_id > 0) ? '' : '<font color="#ff0000"><b>Машина не прикреплена</b></font>',
								ref: 'ferrycarIdCaptionFld'
							},
							{
								xtype: 'hiddenfield',
								alias: 'ferryman_name',
								name: 'ferrycar_id',
								ref: 'ferrycarIdFld'
							}
						], this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS),
					]
				},
				{
					items: [
						...RolesHelper.filterFormFields([
							{
								xtype: 'combobox',
								name: 'ferrycontract',
								ref: 'ferryContractCmb',
								fieldLabel: 'Договор',
								labelSeparator: '',
								labelWidth: 165,
								width: 870,
								queryMode: 'remote',
								displayField: 'name',
								valueField: 'id',
								store: this.ferryContractStore,
								editable: false,
								listConfig: {
									getInnerTpl: function() {
										return '<div style="{[values["sl"] > 90 ? "color:red; font-weight: bold" : ""]}">' +
											'{name}' +
											'</div>';
									}
								},
								listeners: {
									select: {
										fn: function(cmb, rcrd) {
											var contract = rcrd[0];
											var cur = contract.get('currency');

											this.ferryCurrencyFld.setValue(cur);

											if (cur == 'RUR')
												this.ferryCurrencyRateFld.setValue('1');
											else
												this.ferryCurrencyRateFld.setValue(contract.get('rate'));

											this.payStr();
										},
										scope: this
									},
									change: {
										fn: function() {
											this.procContractLimit();
										},
										scope: this
									}
								}
							}
						], this.permissions, RolesHelper.RESOURCE_TRANSPORTATIONS),
					]
				},
				{
					items: [
						...RolesHelper.filterFormFields([
							{
								xtype: 'combobox',
								name: 'ferrymanperson',
								ref: 'ferryPersonCmb',
								width: 870,
								fieldLabel: 'Контактное лицо',
								labelSeparator: '',
								labelWidth: 165,
								queryMode: 'remote',
								displayField: 'name',
								valueField: 'id',
								store: this.ferryPersonStore,
								editable: false
							}
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
						{xtype: 'displayfield', width: 150, value: 'Фактический перевозчик'},
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
								xtype: 'textfield',
								name: 'ferrycarnumber',
								ref: 'ferrycarnumberFld',
								width: ferryCarIsShowed ? 430 : 370,
								labelWidth: ferryCarIsShowed ? 225 : 165,
								fieldLabel: 'Гос.номер а/м',
								labelSeparator: '',
								readOnly: true
							}
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
								xtype: 'textfield',
								name: 'ferryfiodriver',
								ref: 'ferryfiodriverFld',
								fieldLabel: 'ФИО водителя',
								labelSeparator: '',
								width: 370,
								labelWidth: 165,
								readOnly: true,
								style: {
									marginRight: '70px'
								}
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
								height: 100,
								items: [
									{xtype: 'displayfield', width: 170, value: 'Паспортные данные', alias: 'ferrypassport'},
									{
										xtype: 'button',
										alias: 'ferrypassport',
										text: 'Копировать',
										scope: this,
										handler: function () {
											this.ownerModule.app.copyToClipboard(
												this.ferryfiodriverFld.getValue() + ',' +
												this.ferrypassportFld.getValue() + ',' +
												this.ferryphoneFld.getValue() + ',' +
												this.ferrycarFld.getValue() + ' ' + this.ferrycarnumberFld.getValue() + ',' +
												this.ferrycarppFld.getValue() + ' ' + this.ferrycarppnumberFld.getValue()
											);
										}
									}
								]
							},
							{
								xtype: 'textarea',
								name: 'ferrypassport',
								ref: 'ferrypassportFld',
								width: 700,
								height: 100,
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
								style: {
									marginRight: '40px'
								}
							},
							{
								xtype: 'textfield',
								name: 'ferryotherchargestarget',
								width: 540,
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
								width: 200,
								fieldLabel: 'Курс план',
								labelSeparator: '',
								labelWidth: 65,
								value: '0',
								minValue: 0,
								decimalPrecision: 4,
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
										['NDS', 'с НДС'],
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
								labelWidth: 170,
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
								labelSeparator: '',
								labelWidth: 170,
								width: 270,
								allowBlank: true,
								format: 'd.m.Y',
								editable: true,
								startDay: 1
							},
							{
								xtype: 'textfield',
								alias: 'ferryinvoice_actdate',
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
								labelWidth: 170,
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
						labelWidth: 170,
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
			]
		});

		kDesktop.transportation3.transpEdit.ferryPanel.superclass.constructor.call(this, config);

 		this.ownerModule.app.createReference(this);
	},
	onUpdateCarListStore: function() {
		this.ferryCarsStore.load()
	},
	currencyValue: function() {
		var value = this.ferryCurrencyRateFld.getValue() * this.ferryCurrencySumFld.getValue();
		if (this.ferryCurrencyFld.getValue() == 'KZT') value = Number(value / 100).toFixed(4);
		this.ferryCurrencyTotalFld.setValue(value);
	},

	payStr: function() {
		var contract = this.ferryContractCmb.findRecordByValue( this.ferryContractCmb.getValue() );
		var nds = this.parent._s(this.ferryNdsCmb.getRawValue());

		if (contract && nds && nds.length) this.ferryPayCommentFld.setValue(
			'Безналичная оплата ' + nds +
			' на р/счет ' +
			this.parent._s(contract.get('payby')) +
			' верно оформленных товаросопроводительных и закрывающих (Счет, АВР, СФ, ТЗ, информационные письмо (в случае привлечения 3-го лица при межд. перевозке) документов в течение ' +
			this.parent._s(contract.get('paydelay')) +
			' банковских дней'
		);
	},

	procContractLimit: function() {
		if (this.ferryContractCmb) {
			this.ferryContractCmb.removeCls('combobox-bold-red');

			var rec = this.ferryContractCmb.getValue();
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
								return 'Оплата штрафа перевозчиком';
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

		this.grid.on('itemdblclick', function(view, rec, item, index, eventObj, options) {
			this.edit({
				id: rec.get('id'),
				type: rec.get('type'),
				cash: rec.get('cash'),
				currency: rec.get('currency'),
				value: rec.get('value'),
				payorderdate: rec.get('payorderdate'),
				payorder: rec.get('payorder')
			});
		}, this);

		this.grid.on('containercontextmenu', function(view, eventObj){
			var _contextMenu = Ext.create('Ext.menu.Menu', {
				items: [
					{
						text: 'Добавить',
						handler: function (){
							this.edit({
								id: 0
							});
						},
						scope: this
					}
				]
			});

			_contextMenu.showAt(eventObj.getXY());
			eventObj.stopEvent();
		}, this);

		this.grid.on('itemcontextmenu',function(view, rec, node, index, eventObj) {
			var _contextMenu = Ext.create('Ext.menu.Menu', {
				items: [
					{
						text: 'Добавить',
						handler: function (){
							this.edit({
								id: 0
							});
						},
						scope: this
					},
					{
						text: 'Редактировать',
						handler: function (){
							this.edit({
								id: rec.get('id'),
								type: rec.get('type'),
								cash: rec.get('cash'),
								currency: rec.get('currency'),
								value: rec.get('value'),
								payorderdate: rec.get('payorderdate'),
								payorder: rec.get('payorder')
							});
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

	edit: function(data) {
		Ext.create('kDesktop.transportation3.transpEdit.financePanel.editWnd', { ownerModule: this.ownerModule, parent: this, data: data }).show();
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
								data: [
									['IN', 'Поступление'],
									['OUT', 'Списание'],
								]
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
				fieldLabel: 'Дата получения документов от перевозчика',
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
				fieldLabel: 'Дата получения скан копий от перевозчика',
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
		//this.grid.on('itemdblclick', function(view, rec, item, index, eventObj, options) {
		//	this.editClient(rec.get('id'), 'edit');
		//}, this);
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
		console.log(JSON.stringify(config, ['ownerModule', 'parent'], 2)); // Выводит только ownerModule и parent с отступами
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
						{xtype: 'displayfield', width: 200, value: 'Сумма штрафа к клиенту<br>(платит клиент)'},
						{
							xtype: 'numberfield',
							name: 'finetoclient',
							width: 250,
							minValue: 0,
							decimalPrecision: 2,
							hideTrigger:true
						}
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
						}
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
						}
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 200, value: 'Сумма штрафа к перевозчику<br>(платит перевозчик)'},
						{
							xtype: 'numberfield',
							name: 'finetoferry',
							width: 250,
							minValue: 0,
							decimalPrecision: 2,
							hideTrigger:true
						}
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 200, value: 'Сумма штрафа от перевозчика<br>(платим мы)'},
						{
							xtype: 'numberfield',
							name: 'finefromferry',
							width: 250,
							minValue: 0,
							decimalPrecision: 2,
							hideTrigger:true
						}
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 200, value: 'Примечания к штрафам перевозчика'},
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

Ext.define('kDesktop.transportation3.transpEdit.surveerPanel', {
	extend: 'Ext.form.Panel',
	constructor: function(config) {
		config = config || {};

		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.priv = this.parent.priv;
		this.mainPanel = Ext.create('Ext.panel.Panel', {
			region: 'west',
			width: 400,
			border: false,
			frame: true,
			autoScroll: true,
			defaults: { xtype: 'container', layout: { type: 'hbox'} },
			items:[
				{
					items: [
						{xtype: 'displayfield', width: 160, value: 'Распорная штанга выдана'},
						{
							xtype : 'checkbox',
							name: 'surv_spacerbar',
							width: 20
						},
						{
							xtype: 'numberfield',
							name: 'surv_spacerbar_count',
							width: 200,
							minValue: 0,
							decimalPrecision: 0,
							hideTrigger:true
						}
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 160, value: 'Распорная штанга получена'},
						{
							xtype : 'checkbox',
							name: 'surv_spacerbar_rcvd',
							width: 20
						},
						{
							xtype: 'datefield',
							name: 'surv_spacerbar_rcvd_date',
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
						{xtype: 'displayfield', width: 180, value: 'Получил от'},
						{
							xtype: 'textfield',
							width: 200,
							name: 'surv_spacerbar_fio'
						}
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 160, value: 'CMR выданы'},
						{
							xtype : 'checkbox',
							name: 'surv_crm',
							width: 20
						},
						{
							xtype: 'numberfield',
							name: 'surv_crm_count',
							width: 200,
							minValue: 0,
							decimalPrecision: 0,
							hideTrigger:true
						}
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 180, value: 'Компания'},
						{
							xtype: 'combobox',
							name: 'surv_crm_company',
							ref: 'survCrmCompanyCmb',
							width: 200,
							queryMode: 'local',
							displayField: 'value',
							valueField: 'key',
							store: Ext.create('Ext.data.JsonStore', {fields: ['key', 'value'], idProperty: 'key'})
						}
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 160, value: 'Маяк слежения выдан'},
						{
							xtype : 'checkbox',
							name: 'surv_beacon',
							width: 20
						},
						{
							xtype: 'combobox',
							width: 180,
							name: 'surv_beacon_num',
							ref: 'survBeaconNumCmb',
							queryMode: 'remote',
							mode: 'local',
							//pageSize: 40,
							displayField: 'value',
							valueField: 'id',
							typeAhead: true,
							minChars: 2,
							store: Ext.create('Ext.data.JsonStore', {
								autoSave: false,
								proxy: {
									actionMethods: 'POST',
									type: 'ajax',
									url: this.ownerModule.app.connectUrl,
									extraParams: {
										module: this.ownerModule.moduleId,
										method: 'survBeaconNumCmb'
									},
									reader: {
										type: 'json',
										root: 'items',
										totalProperty: 'totalCount'
									}
								},
								fields: ['id', 'value'],
								idproperty: 'id',
								totalProperty: 'totalCount'
							})
						},
						{
							xtype: 'button',
							text: "+",
							handler: function() {
								Ext.Msg.show({
									prompt: true,
									title:'Добавить?',
									msg: 'Введите номер маяка',
									buttons: Ext.Msg.OKCANCEL,
									icon: Ext.Msg.QUESTION,
									fn: function(btn, text, cfg) {
										if (btn == 'ok') {
											this.ownerModule.app.doAjax({
												module: this.ownerModule.moduleId,
												method: 'survBeaconNumAdd',
												value: text
											},
											function(res) {
												this.mainPanel.survBeaconNumCmb.getStore().load();
											},
											this, this);
										}
									},
									scope: this
								});
							},
							scope: this
						},
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 180, value: 'Комментарий'},
						{
							xtype: 'textarea',
							name: 'surv_comment',
							width: 200,
							height: 100
						}
					]
				},
				{
					items: [
						{xtype: 'displayfield', width: 180, value: 'Фактическая печать в CMR'},
						{
							xtype: 'textfield',
							width: 200,
							name: 'surv_factprint'
						}
					]
				}
			]
		});
		this.docTypeStore = Ext.create('Ext.data.JsonStore', {fields: ['key', 'value'], idProperty: 'key'});
		this.docGridStore = Ext.create('Ext.data.Store', {
			pageSize: 40,
			root: 'items',
			idProperty: 'id',
			remoteSort: true,
			autoLoad: true,
			fields: [
				'id',
				'date_str',
				'type',
				'type_str',
				'userlogin',
				'url',
				'comment'
				],
			proxy: {
				actionMethods: 'POST',
				type: 'ajax',
				url: this.ownerModule.app.connectUrl,
				extraParams: {
					module: this.ownerModule.moduleId,
					method: 'surveerDocGrid',
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
			title: 'Документы',
			region: 'center',
			split: false,
			border: true,
			store: this.docGridStore,
			loadMask: true,
			columnLines: true,
			scope: this,
			columns:[
				{
					header: "Тип",
					dataIndex: 'type_str',
					width: 200,
					sortable: true
				},
				{
					header: "",
					dataIndex: 'url',
					width: 70,
					sortable: false,
					renderer: function(value, metaData, record) {
						if (record.get('id')) return '<a target=\'_blank\' href=\''+this.scope.ownerModule.app.connectUrl+'?module='+this.scope.ownerModule.moduleId+'&method=downloadSurveerDoc&id='+record.get('id')+'\'>Скачать</a>';
						else return '';
					}
				},
				{
					header: "Примечание",
					dataIndex: 'comment',
					width: 200,
					sortable: true
				},
				{
					header: "Дата",
					dataIndex: 'date_str',
					width: 80,
					sortable: true
				},
				{
					header: "Пользователь",
					dataIndex: 'userlogin',
					width: 100,
					sortable: true
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

		kDesktop.transportation3.transpEdit.surveerPanel.superclass.constructor.call(this, config);

		this.ownerModule.app.createReference(this.mainPanel);
	},

	editDoc: function(data) {
		Ext.create('kDesktop.transportation3.transpEdit.surveerPanel.editDocWnd', {
			ownerModule: this.ownerModule,
			parent: this,
			data: data,
		}).show();
	},

	showMask: function(msg) {
		this.body.mask(msg + '...', 'x-mask-loading');
	},

	hideMask: function() {
		this.body.unmask();
	}
});

Ext.define('kDesktop.transportation3.transpEdit.surveerPanel.editDocWnd', {
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
							store: this.parent.docTypeStore,
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

		kDesktop.transportation3.transpEdit.surveerPanel.editDocWnd.superclass.constructor.call(this, config);

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
								method: 'saveSurveerDoc',
								tid: me.parent.parent.oid
							},
							waitMsg: 'Сохраняется...',
							success: function(form, action) {
								me.parent.docGridStore.load();
								me.close();
							},
							failure: function(form, action) {
								Ext.Msg.alert('Ошибка', action.result.msg);
								this.mainForm.remove(this.mainForm.uploadField);
								this.mainForm.add({
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
								this.ownerModule.app.createReference(this.mainForm);
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

Ext.define('kDesktop.transportation3.transpEdit.curDiffPanel', {
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
// 				{
// 					xtype : 'container',
// 					layout: {
// 						type: 'hbox'
// 					},
// 					items: [
// 						{
// 							xtype: 'button',
// 							text: "Рассчитать",
// 							handler: function() {
// 								this.calc();
// 							},
// 							scope: this
// 						},
// 					]
// 				},
				{
					xtype: 'textarea',
					ref: 'logArea',
// 					width: 880,
// 					height: 730,
					readOnly: true
				}
			]
		});

		kDesktop.transportation3.transpEdit.curDiffPanel.superclass.constructor.call(this, config);

		this.ownerModule.app.createReference(this);

		this.on('afterrender', function() {
			this.calc();
		}, this);
	},

	calc: function() {
		kDesktop.app.doAjax({
			module: this.ownerModule.moduleId,
			method: 'getCalculations',
			id: this.parent.oid
		},
		function(res) {
			this.logArea.setValue(res.log);
		},
		this, this);
	},

	showMask: function(msg) {
		this.body.mask(msg + '...', 'x-mask-loading');
	},

	hideMask: function() {
		this.body.unmask();
	}
});
