Ext.define('kDesktop.ticket2', {
	extend: 'Ext.tab.Panel',
	moduleId: 'ticket2',
	constructor: function(config) {
		config = config || {};
		this.app = config.app;
		this.newWNDID = 0;
		Ext.applyIf(config, {
			border: false,
			closable: false,
			title: '',
			layout: 'fit',
			activeItem: 0,
			items: [
				Ext.create('kDesktop.ticket2.tickets', {
					ownerModule: this,
					parent: this,
					data: config?.data ?? {}
				})
			]
		});
		
		kDesktop.ticket2.superclass.constructor.call(this, config);
	},
	
	getTabItem: function(id) {
		var tab = null;
		this.items.each(function(item) {
			if (item.uid == id) {
				tab = item;
				return false;
			}
			return true;
		});
		return tab;
	},
	
	getNewWNDID: function() {
		this.newWNDID++;
		return this.newWNDID;
	},

	showMask: function(msg) {
		this.body.mask(msg + '...', 'x-mask-loading');
	},

	hideMask: function() {
		this.body.unmask();
	}
});

Ext.define('kDesktop.ticket2.tickets', {
	extend: 'Ext.panel.Panel',
	constructor: function(config) {
		config = config || {};
		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		const configData = config?.data ?? {}
		const RolesHelper = helpers.roles
		const TypesHelper = helpers.types
		this.permissions = TypesHelper.isObjectAndHasProps(configData?.permissions) ? configData.permissions : {}
		this.priv = TypesHelper.isObjectAndHasProps(configData?.priv) ? configData.priv : {}
		this.userList = TypesHelper.isObjectAndHasProps(configData?.userList) ? configData.userList : []
		this.uid = 'ticket2-tickets';
		this.title = 'Запросы';
		this.closable = false;

		const storeFields = [
			'id',
			{name: 'type', type: 'int'},
			'type_text',
			{name: 'status', type: 'int'},
			'date_str',
			{name: 'logist', type: 'int'},
			'logist_login',
			'load_str',
			'offload_str',
			'fromplace',
			'toplace',
			{name: 'manager', type: 'int'},
			'manager_login',
			{name: 'client_id', type: 'int'},
			{name: 'person_id', type: 'int'},
			'client_name',
			'person_name',
			'cartype',
			'description',
			'price',
			'answer',
			'clientprice',
			'comment',
			'clientcomment',
			{name: 'fromlk', type: 'int'},
			'docs',
			'exec_interval',
			{name: 'exec_interval_expire', type: 'int'}
		]
		this.store = Ext.create('Ext.data.Store', {
			pageSize: 40,
			root: 'items',
			idProperty: 'id',
			remoteSort: true,
			autoLoad: true,
			fields: RolesHelper.filterStoreFields(storeFields, this.permissions, RolesHelper.RESOURCE_CRM),
			proxy: {
				actionMethods: 'POST',
				type: 'ajax',
				url: this.ownerModule.app.connectUrl,
				extraParams: {
					module: this.ownerModule.moduleId,
					method: 'ticketGrid'
				},
				reader: {
					type: 'json',
					root: 'items',
					totalProperty: 'totalCount'
				}
			},
			// TODO remove
			/*listeners: {
				load: function (store, records, successful, operation) {
					if (successful) {
						console.log('Данные загружены:', records);
						records.forEach(record => {
							console.log(record.getData()); // Вывод данных каждой записи
						});
					} else {
						console.error('Ошибка загрузки данных:', operation.getError());
					}
				}
			}*/
		});
		this.store.on('beforeload', function(){
			this.infoTpl.overwrite(this.infoPnl.body, new Object());
			
			this.store.proxy.extraParams.searchId = this.filterPnl.searchIdVal.getValue();
			this.store.proxy.extraParams.searchClient = this.filterPnl.searchClient.getValue();
			this.store.proxy.extraParams.searchFromPlace = this.filterPnl.searchFromPlaceCmb.getRawValue();
			this.store.proxy.extraParams.searchToPlace = this.filterPnl.searchToPlaceCmb.getRawValue();
			this.store.proxy.extraParams.searchManager = this.filterPnl.searchManagerCmb.getValue();
			this.store.proxy.extraParams.searchLogist = this.filterPnl.searchLogistCmb.getValue();
			
			this.store.proxy.extraParams.searchDate1 = this.filterPnl.searchDate1Fld.getRawValue();
			this.store.proxy.extraParams.searchDate2 = this.filterPnl.searchDate2Fld.getRawValue();

			this.store.proxy.extraParams.loaddate1 = this.filterPnl.loaddate1Fld.getRawValue();
			this.store.proxy.extraParams.loaddate2 = this.filterPnl.loaddate2Fld.getRawValue();
			this.store.proxy.extraParams.offloaddate1 = this.filterPnl.offloaddate1Fld.getRawValue();
			this.store.proxy.extraParams.offloaddate2 = this.filterPnl.offloaddate2Fld.getRawValue();
		},this);
		this.store.on('load', function() {
			if ( (this.store.getTotalCount() > 0) && (this.grid) )
				this.grid.getSelectionModel().select(0);
		},this);

		const clientStore = Ext.create('Ext.data.Store', {
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

		this.filterPnl = Ext.create('Ext.panel.Panel', {
			region: 'north',
			border: false,
			frame: true,
			items:[
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 100, value: 'Дата создания'},
						{
							xtype: 'datefield',
							ref: 'searchDate1Fld',
							width: 100,
							allowBlank: true,
							format: 'd.m.Y',
							editable: false,
							startDay: 1
						},
						{
							xtype: 'datefield',
							ref: 'searchDate2Fld',
							width: 100,
							allowBlank: true,
							format: 'd.m.Y',
							editable: false,
							startDay: 1
						},
						{xtype: 'displayfield', width: 50, value: ''},
						{
							xtype: 'combobox',
							fieldLabel: 'Клиент',
							ref: 'searchClient',
							labelSeparator: '',
							labelWidth: 45,
							itemId: 'clientId',
							width: 230,
							margin: '0 0 0 10',
							queryMode: 'remote',
							displayField: 'name',
							valueField: 'id',
							store: clientStore,
							minChars: 3
						},
						{xtype: 'displayfield', width: 40, value: ''},
						{xtype: 'displayfield', width: 100, value: 'Дата загрузки'},
						{xtype: 'displayfield', width: 10, value: 'с'},
						{
							xtype: 'datefield',
							name: 'loaddate1',
							ref: 'loaddate1Fld',
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
							ref: 'loaddate2Fld',
							width: 113,
							allowBlank: true,
							format: 'd.m.Y',
							editable: true,
							startDay: 1
						},
					]
				},
				{
					xtype : 'container',
					layout: {
						type: 'hbox'
					},
					items: [
						{xtype: 'displayfield', width: 100, value: 'Направление'},
						{
							xtype: 'combobox',
							width: 250,
							ref: 'searchFromPlaceCmb',
							queryMode: 'remote',
							pageSize: 40,
							displayField: 'place',
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
										method: 'ticketPlace'
									},
									reader: {
										type: 'json',
										root: 'items',
										totalProperty: 'totalCount'
									}
								},
								fields: ['id', 'place'],
								idproperty: 'id',
								totalProperty: 'totalCount'
							})
						},
						{
							xtype: 'combobox',
							width: 250,
							ref: 'searchToPlaceCmb',
							queryMode: 'remote',
							pageSize: 40,
							displayField: 'place',
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
										method: 'ticketPlace'
									},
									reader: {
										type: 'json',
										root: 'items',
										totalProperty: 'totalCount'
									}
								},
								fields: ['id', 'place'],
								idproperty: 'id',
								totalProperty: 'totalCount'
							})
						},
						{xtype: 'displayfield', width: 40, value: ''},
						{xtype: 'displayfield', width: 100, value: 'Дата выгрузки'},
						{xtype: 'displayfield', width: 10, value: 'с'},
						{
							xtype: 'datefield',
							name: 'offloaddate1',
							ref: 'offloaddate1Fld',
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
							ref: 'offloaddate2Fld',
							width: 113,
							allowBlank: true,
							format: 'd.m.Y',
							editable: true,
							startDay: 1
						}
					]
				},
				{
					xtype : 'container',
					layout: {
						type: 'hbox'
					},
					items: [
						{xtype: 'displayfield', width: 100, value: 'Менеджер'},
						{
							xtype: 'combobox',
							width: 250,
							ref: 'searchManagerCmb',
							queryMode: 'local',
							displayField: 'value',
							valueField: 'key',
							value: 0,
							store: Ext.create('Ext.data.ArrayStore', {
								fields: [
									'key',
									'value'
								]
							})
						},
						{xtype: 'displayfield', width: 20, value: ''},
						{xtype: 'displayfield', width: 50, value: 'Номер'},
						{
							xtype: 'numberfield',
							ref: 'searchIdVal',
							width: 180,
							minValue: 0,
							decimalPrecision: 0,
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
						{xtype: 'displayfield', width: 100, value: 'Логист'},
						{
							xtype: 'combobox',
							width: 250,
							ref: 'searchLogistCmb',
							queryMode: 'local',
							displayField: 'value',
							valueField: 'key',
							value: 0,
							store: Ext.create('Ext.data.ArrayStore', {
								fields: [
									'key',
									'value'
								]
							})
						},
						{xtype: 'displayfield', width: 20, value: ''},
						{
							xtype: 'button',
							text: 'Загрузить',
							width: 70,
							scope: this,
							handler: function (){
								this.store.load();
							}
						}
					]
				}
			]
		});
		this.ownerModule.app.createReference(this.filterPnl);
		
		this.gridBbar = Ext.create('Ext.toolbar.Paging', {
			store: this.store,
			displayInfo: true,
			displayMsg: 'Записи {0} - {1} из {2}',
			emptyMsg: "Нет записей"
		});
		const cols = [
			{
				header: "Номер",
				dataIndex: 'id',
				width: 100,
				sortable: false
			},
			{
				header: "Тип",
				dataIndex: 'type_text',
				width: 100,
				sortable: false,
				renderer: function(value, metaData, record)
				{
					if ( (record.get("status") == 0) && (record.get("type") == 1) )
						metaData.style = "background-color : #f465d7 !important";
					else if ( (record.get("status") == 0) && (record.get("type") == 2) )
						metaData.style = "background-color : #f37373 !important";

					else if ( (record.get("status") == 1) && (record.get("type") == 1) )
						metaData.style = "background-color : #4ed795 !important";
					else if ( (record.get("status") == 1) && (record.get("type") == 2) )
						metaData.style = "background-color : #c9d74e !important";

					return value;
				}
			},
			{
				header: "Дата создания",
				dataIndex: 'date_str',
				width: 100,
				sortable: false
			},
			{
				header: "Время выполнения",
				dataIndex: 'exec_interval',
				width: 110,
				sortable: false,
				renderer: function(value, metaData, record)
				{
					if (record.get("exec_interval_expire") == 1)
						metaData.style = "background-color : #ff6b6b !important";

					return value;
				}
			},
			{
				header: "Логист",
				dataIndex: 'logist_login',
				width: 100,
				sortable: false
			},
			{
				header: "Дата загр",
				dataIndex: 'load_str',
				width: 70,
				sortable: false
			},
			{
				header: "Дата выгр",
				dataIndex: 'offload_str',
				width: 70,
				sortable: false
			},
			{
				header: "Откуда",
				dataIndex: 'fromplace',
				width: 150,
				sortable: false
			},
			{
				header: "Куда",
				dataIndex: 'toplace',
				width: 150,
				sortable: false
			},
			{
				header: "Менеджер",
				dataIndex: 'manager_login',
				width: 100,
				sortable: false
			},
			{
				header: "",
				dataIndex: 'fromlk',
				width: 30,
				sortable: false,
				renderer: function(value, metaData, record)
				{
					if ( value == 1 ) {
						metaData.style = "background-color : #fef200 !important";
						return 'к';
					}
					else return 'м';
				}
			},
			{
				header: "Клиент",
				dataIndex: 'client_name',
				width: 100,
				sortable: false
			},
			{
				header: "Тип а/м",
				dataIndex: 'cartype',
				width: 60,
				sortable: false
			},
			{
				header: "Описание, характер груза",
				dataIndex: 'description',
				width: 150,
				sortable: false
			},
			{
				header: "Стоимость",
				dataIndex: 'price',
				width: 100,
				sortable: false
			},
			{
				header: "Ответ",
				dataIndex: 'answer',
				width: 200,
				sortable: false
			},
			{
				header: "Стоимость для клиента",
				dataIndex: 'clientprice',
				width: 100,
				sortable: false
			},
			{
				header: "Комментарий",
				dataIndex: 'comment',
				width: 100,
				sortable: false
			},
			{
				header: "Комментарий для клиента",
				dataIndex: 'cientcomment',
				width: 100,
				sortable: false
			}
		]
		this.grid = Ext.create('Ext.grid.Panel', {
			region: 'center',
			store: this.store,
			loadMask: true,
			columnLines: true,
			columns: RolesHelper.filterGridColumns(cols, this.permissions, RolesHelper.RESOURCE_CRM),
			viewConfig: {
				stripeRows: true
			},
			tbar: this.gridTbar,
			bbar: this.gridBbar
		});
		this.grid.on('containercontextmenu', function(view, eventObj){
			const items = [
				{
					text: 'Добавить запрос',
					iconCls: 'add-icon',
					scope: this,
					handler: function (){
						this.addTicket()
					},
				},
			]
			
			if (this.priv && this.priv.ticket && this.priv.ticket.modExport && (this.priv.ticket.modExport == 1)) {
				if (items.length > 0) items.push('-');
				items.push({
					text: 'Экспорт',
					iconCls: 'docs-icon',
					scope: this,
					handler: function (){
						this.exportData();
					}
				});
			}
				
			var _contextMenu = Ext.create('Ext.menu.Menu', { items: items });
			_contextMenu.showAt(eventObj.getXY());
			eventObj.stopEvent();
		}, this);
		this.grid.on('itemcontextmenu',function(view, rec, node, index, eventObj) {
			const items = [
				{
					text: 'Добавить запрос',
					iconCls: 'add-icon',
					scope: this,
					handler: function (){
						this.addTicket()
					}
				},
				{

					text: 'Создать заявку',
					iconCls: 'add-icon',
					handler:  () => this.createDeal(rec)
				}
			]

			if (this.priv && this.priv.userId) {
				//if ( (rec.get('manager') != this.priv.userId) && (rec.get('logist') == 0) && (rec.get('status') == 0)) {
				if ( (rec.get('logist') == 0) && (rec.get('status') == 0)) {
					if (items.length == 1) items.push('-');
					items.push({
						text: 'Принять в обработку',
						iconCls: 'ok-icon',
						scope: this,
						handler: function (){
							Ext.MessageBox.confirm('Принять в обработку?', 'Вы уверены что хотите?',
								function(btn){
									if(btn == 'yes') {
										this.ownerModule.app.doAjax({
											module: this.ownerModule.moduleId,
											method: 'procTicket',
											id: rec.get('id'),
											action: 'open'
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
					});
				}
				
				if ( (rec.get('logist') == this.priv.userId) && (rec.get('status') == 1) ) {
					if (items.length == 1) items.push('-');
					items.push({
						text: 'Отказаться от обработки',
						iconCls: 'cancel-icon',
						scope: this,
						handler: function (){
							Ext.MessageBox.confirm('Отказаться от обработки?', 'Вы уверены что хотите?',
								function(btn){
									if(btn == 'yes') {
										this.ownerModule.app.doAjax({
											module: this.ownerModule.moduleId,
											method: 'procTicket',
											id: rec.get('id'),
											action: 'close'
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
					});
					items.push({
						text: 'Закрыть',
						iconCls: 'ok-icon',
						scope: this,
						handler: function (){
							Ext.create('kDesktop.ticket2.closeTicketWnd', { ownerModule: this.ownerModule, parent: this, ticket_id: rec.get('id') }).show();
						}
					});
				}

				if (
					(this.priv && this.priv.ticket && this.priv.ticket.modChangeStatus && (this.priv.ticket.modChangeStatus == 1)) ||
					( rec.get('manager') == this.priv.userId )
				) {
					if (items.length == 1) items.push('-');
					items.push({
						text: 'Сменить статус',
						iconCls: 'edit-icon',
						scope: this,
						menu: Ext.create('Ext.menu.Menu', {
							items:[
								{
									text: 'Открыт',
									scope: this,
									handler: function (){
										Ext.Msg.confirm('Открыть?', 'Вы уверены что хотите?',
											function(btn){
												if(btn == 'yes')
												{
													this.ownerModule.app.doAjax({
														module: this.ownerModule.moduleId,
														method: 'setTicketStatus',
														id: rec.get('id'),
														status: 'open'
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
								},
								{
									text: 'Закрыт',
									scope: this,
									handler: function (){
										Ext.Msg.confirm('Закрыть?', 'Вы уверены что хотите?',
											function(btn){
												if(btn == 'yes')
												{
													this.ownerModule.app.doAjax({
														module: this.ownerModule.moduleId,
														method: 'setTicketStatus',
														id: rec.get('id'),
														status: 'close'
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
								},
								{
									text: 'Ошибочный',
									scope: this,
									handler: function (){
										Ext.Msg.confirm('Ошибочный?', 'Вы уверены что хотите?',
											function(btn){
												if(btn == 'yes')
												{
													this.ownerModule.app.doAjax({
														module: this.ownerModule.moduleId,
														method: 'setTicketStatus',
														id: rec.get('id'),
														status: 'error'
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
						})
					});
				}
				if ((rec.get('manager') === this.priv.userId) && (rec.get('status') === 5)) {
					if (items.length === 1) items.push('-')
					items.push({
						text: 'Стоимость для клиента',
						iconCls: 'ok-icon',
						scope: this,
						handler: function () {
							Ext.create('kDesktop.ticket2.setTicketClientPriceWnd', {
								ownerModule: this.ownerModule,
								parent: this,
								ticket_id: rec.get('id')
							}).show()
						}
					})
				}
			}
			
			if (
				( (this.priv) && ( rec.get('manager') == this.priv.userId ) && (rec.get('status') > 5) ) ||
				( this.priv && this.priv.ticket && this.priv.ticket.modEdit && (this.priv.ticket.modEdit == 1) )
			)
			{
				if (items.length == 1) items.push('-');
				items.push({
					text: 'Редактировать',
					iconCls: 'edit-icon',
					scope: this,
					handler: function (){
						this.editTicket(rec.get('id'));
					}
				});
			}

			if (this.priv && this.priv.ticket && this.priv.ticket.modExport && (this.priv.ticket.modExport == 1)) {
				if (items.length > 0) items.push('-');
				items.push({
					text: 'Экспорт',
					iconCls: 'docs-icon',
					scope: this,
					handler: function (){
						this.exportData();
					}
				});
			}
			
			if (items.length > 0) items.push('-');
			items.push({
				text: 'Ком. предложение',
				iconCls: 'docs-icon',
				scope: this,
				handler: function (){
					var url = this.ownerModule.app.connectUrl+'?module='+this.ownerModule.moduleId+'&method=downloadOffer&id='+rec.get('id');
					window.open(url, "download");
				}
			});

			var _contextMenu = Ext.create('Ext.menu.Menu', {
				items: items
			});

			_contextMenu.showAt(eventObj.getXY());
			eventObj.stopEvent();
		}, this);
		this.grid.on('select', function(sm, record, rowIndex, eOpts){
			var template = this.buildInfoTpl(record.data);
			template.overwrite(this.infoPnl.body, record.data);
			
			if (!this.awaitingClientPrice)
				this.grid10.getSelectionModel().deselect( this.grid10.getSelectionModel().getSelection() );
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

		if (!config.awaitingClientPrice) {
			this.store10 = Ext.create('Ext.data.Store', {
				pageSize: 40,
				root: 'items',
				idProperty: 'id',
				remoteSort: true,
				autoLoad: true,
				fields: [
					'id',
					'description10',
					'type_text',
					{name: 'status', type: 'int'},
					'date_str',
					{name: 'logist', type: 'int'},
					'logist_login',
					'load_str',
					'offload_str',
					'fromplace',
					'toplace',
					{name: 'manager', type: 'int'},
					'manager_login',
					'client10',
					'cartype',
					'description',
					'price',
					'answer',
					'clientprice',
					'comment',
					'clientcomment',
					'exec_interval',
					{name: 'exec_interval_expire', type: 'int'}
				],
				proxy: {
					actionMethods: 'POST',
					type: 'ajax',
					url: this.ownerModule.app.connectUrl,
					extraParams: {
						module: this.ownerModule.moduleId,
						method: 'ticketGrid10'
					},
					reader: {
						type: 'json',
						root: 'items',
						totalProperty: 'totalCount'
					}
				}
			});
			
			this.gridBbar10 = Ext.create('Ext.toolbar.Paging', {
				store: this.store10,
				displayInfo: true,
				displayMsg: 'Записи {0} - {1} из {2}',
				emptyMsg: "Нет записей"
			});

			this.grid10 = Ext.create('Ext.grid.Panel', {
				region: 'south',
				split: true,
				height: 300,
				store: this.store10,
				loadMask: true,
				columnLines: true,
				columns:[
					{
						header: "Номер",
						dataIndex: 'id',
						width: 100,
						sortable: false
					},
					{
						header: "Тип",
						dataIndex: 'type_text',
						width: 100,
						sortable: false,
						renderer: function(value, metaData, record)
						{
							if (record.get("status") == 0)
								metaData.style = "background-color : #f465d7 !important";
							else if (record.get("status") == 1)
								metaData.style = "background-color : #4ed795 !important";
							
							return value;
						}
					},
					{
						header: "",
						dataIndex: 'description10',
						width: 100,
						sortable: false
					},
					{
						header: "Дата создания",
						dataIndex: 'date_str',
						width: 100,
						sortable: false
					},
					{
						header: "Время выполнения",
						dataIndex: 'exec_interval',
						width: 110,
						sortable: false,
						renderer: function(value, metaData, record)
						{
							if (record.get("exec_interval_expire") == 1)
								metaData.style = "background-color : #ff6b6b !important";
							
							return value;
						}
					},
					{
						header: "Логист",
						dataIndex: 'logist_login',
						width: 100,
						sortable: false
					},
					{
						header: "Дата загр",
						dataIndex: 'load_str',
						width: 70,
						sortable: false
					},
					{
						header: "Дата выгр",
						dataIndex: 'offload_str',
						width: 70,
						sortable: false
					},
					{
						header: "Откуда",
						dataIndex: 'fromplace',
						width: 150,
						sortable: false
					},
					{
						header: "Куда",
						dataIndex: 'toplace',
						width: 150,
						sortable: false
					},
					{
						header: "Менеджер",
						dataIndex: 'manager_login',
						width: 100,
						sortable: false
					},
					{
						header: "Тип а/м",
						dataIndex: 'cartype',
						width: 60,
						sortable: false
					},
					{
						header: "Описание",
						dataIndex: 'description',
						width: 150,
						sortable: false
					},
					{
						header: "Заказчик",
						dataIndex: 'client10',
						width: 150,
						sortable: false
					},
					{
						header: "Ставка",
						dataIndex: 'clientprice',
						width: 100,
						sortable: false
					},
					{
						header: "Описание груза",
						dataIndex: 'answer',
						width: 200,
						sortable: false
					},
					{
						header: "Комментарий",
						dataIndex: 'comment',
						width: 200,
						sortable: false
					}
				],
				viewConfig: {
					stripeRows: true
				},
				bbar: this.gridBbar10
			});
			this.grid10.on('containercontextmenu', function(view, eventObj){
				const items = [
					{
						text: 'Добавить запрос',
						iconCls: 'add-icon',
						scope: this,
						handler: function (){
							this.addTicket10()
						}
					},
				]
					
				var _contextMenu = Ext.create('Ext.menu.Menu', { items: items });
				_contextMenu.showAt(eventObj.getXY());
				eventObj.stopEvent();
			}, this);
			this.grid10.on('itemcontextmenu',function(view, rec, node, index, eventObj) {
				const items = [
					{
						text: 'Добавить запрос',
						iconCls: 'add-icon',
						scope: this,
						handler: function (){
							this.addTicket10()
						}
					},
				]
				if (this.priv && this.priv.userId) {
					//if ( (rec.get('manager') != this.priv.userId) && (rec.get('logist') == 0) && (rec.get('status') == 0)) {
					if ( (rec.get('logist') == 0) && (rec.get('status') == 0)) {
						if (items.length == 1) items.push('-');
						items.push({
							text: 'Принять в обработку',
							iconCls: 'ok-icon',
							scope: this,
							handler: function (){
								Ext.MessageBox.confirm('Принять в обработку?', 'Вы уверены что хотите?',
									function(btn){
										if(btn == 'yes') {
											this.ownerModule.app.doAjax({
												module: this.ownerModule.moduleId,
												method: 'procTicket',
												id: rec.get('id'),
												action: 'open'
											},
											function(res) {
												this.gridBbar10.doRefresh();
											},
											this, this);
										}
									},
									this
								);
							}
						});
					}

					if ( (rec.get('logist') == this.priv.userId) && (rec.get('status') == 1) ) {
						if (items.length == 1) items.push('-');
						items.push({
							text: 'Отказаться от обработки',
							iconCls: 'cancel-icon',
							scope: this,
							handler: function (){
								Ext.MessageBox.confirm('Отказаться от обработки?', 'Вы уверены что хотите?',
									function(btn){
										if(btn == 'yes') {
											this.ownerModule.app.doAjax({
												module: this.ownerModule.moduleId,
												method: 'procTicket',
												id: rec.get('id'),
												action: 'close'
											},
											function(res) {
												this.gridBbar10.doRefresh();
											},
											this, this);
										}
									},
									this
								);
							}
						});
						items.push({
							text: 'Закрыть',
							iconCls: 'ok-icon',
							scope: this,
							handler: function (){
								Ext.create('kDesktop.ticket2.closeTicket10Wnd', { ownerModule: this.ownerModule, parent: this, ticket_id: rec.get('id') }).show();
							}
						});
					}
				}

				var _contextMenu = Ext.create('Ext.menu.Menu', {
					items: items
				});

				_contextMenu.showAt(eventObj.getXY());
				eventObj.stopEvent();
			}, this);
			this.grid10.on('select', function(sm, record, rowIndex, eOpts){
				var template = this.buildInfoTpl10(record.data);
				template.overwrite(this.infoPnl.body, record.data);
				
				this.grid.getSelectionModel().deselect( this.grid.getSelectionModel().getSelection() );
			}, this);
			this.grid10.getView().on('render', function(view) {
				view.tip = Ext.create('Ext.tip.ToolTip', {
					target: view.el,
					delegate: view.cellSelector,
					trackMouse: true,
					autoHide: false,
					listeners: {
						'beforeshow': {
							fn: function(tip){
								var msg;
								var record = this.grid10.getView().getRecord(tip.triggerElement.parentNode);
								msg = Ext.get(tip.triggerElement).dom.childNodes[0].innerHTML;
								tip.update(msg.replace(/\n/g, '<br/>'));
							},
							scope: this
						}
					}
				});
			}, this);
		}
		
		this.infoTpl = new Ext.Template(
			'выберите запись'
		);
		this.infoPnl =  Ext.create('Ext.panel.Panel', {
			region: 'east',
			width: 400,
			split: true,
			autoScroll: true,
			bodyStyle: {
				background: '#ffffff',
				padding: '10px'
			}
		});
		
		Ext.applyIf(config, {
			border: false,
			layout: 'border',
			items: [
				this.filterPnl,
				{
					xtype: 'panel',
					region: 'center',
					border: false,
					layout: 'border',
					items: !config.awaitingClientPrice ? [
						this.grid,
						this.grid10
					] : [this.grid]
				},
				this.infoPnl
			]
		});

		kDesktop.ticket2.tickets.superclass.constructor.call(this, config);

		this.on('afterrender', function() {
			this.filterPnl.searchManagerCmb.store.loadData(this.userList)
			this.filterPnl.searchManagerCmb.select(0);

			this.filterPnl.searchLogistCmb.store.loadData(this.userList)
			this.filterPnl.searchLogistCmb.select(0);
		}, this);
	},
	
	exportData: function() {
		const url = this.ownerModule.app.connectUrl+'?module='+this.ownerModule.moduleId+'&method=exportData&data='+Ext.encode(this.store.proxy.extraParams);
		window.open(url, "download");
	},
	
	addTicket: function() {
		this.ownerModule.app.doAjax({
			module: this.ownerModule.moduleId,
			method: 'onAddTicket'
		},
		function(res) {
			Ext.create('kDesktop.ticket2.addTicketWnd', { ownerModule: this.ownerModule, parent: this, data: res }).show();
		},
		this, this);
	},
	
	addTicket10: function() {
		this.ownerModule.app.doAjax({
			module: this.ownerModule.moduleId,
			method: 'onAddTicket'
		},
		function(res) {
			Ext.create('kDesktop.ticket2.addTicket10Wnd', { ownerModule: this.ownerModule, parent: this, data: res }).show();
		},
		this, this);
	},
	
	editTicket: function(id) {
		this.ownerModule.app.doAjax({
			module: this.ownerModule.moduleId,
			method: 'onEditTicket',
			id: id
		},
		function(res) {
			Ext.create('kDesktop.ticket2.editTicketWnd', { ownerModule: this.ownerModule, parent: this, data: res }).show();
		},
		this, this);
	},
	
	buildInfoTpl: function(rec) {
		var txt = ''
		txt += '<b>Номер:</b> {id:this.formatNull}<br/>';
		txt += '<b>Тип:</b> {type_text:this.formatNull}<br/>';
		txt += '<b>Дата создания:</b> {date_str:this.formatNull}<br/>';
		txt += '<b>Логист:</b> {logist_login:this.formatNull}<br/>';
		txt += '<b>Дата загрузки:</b> {load_str:this.formatNull}<br/>';
		txt += '<b>Дата выгрузки:</b> {offload_str:this.formatNull}<br/>';
		txt += '<b>Откуда:</b> {fromplace:this.formatNull}<br/>';
		txt += '<b>Куда:</b> {toplace:this.formatNull}<br/>';
		txt += '<b>Менеджер:</b> {manager_login:this.formatNull}<br/>';
		txt += '<b>Клиент:</b> {client_name:this.formatNull}<br/>';
		txt += '<b>Контактное лицо:</b> {person_name:this.formatNull}<br/>';
		txt += '<b>Тип а/м:</b> {cartype:this.formatNull}<br/>';
		txt += '<b>Описание, характер груза:</b> {description:this.formatNull}<br/>';
		txt += '<b>Стоимость:</b> {price:this.formatNull}<br/>';
		txt += '<b>Ответ:</b> {answer:this.formatNull}<br/>';
		txt += '<b>Стоимость для клиента:</b> {clientprice:this.formatNull}<br/>';
		txt += '<b>Комментарий:</b> {comment:this.formatNull}<br/>';
		txt += '<b>Комментарий для клиента:</b> {clientcomment:this.formatNull}<br/>';

		if (this.priv && this.priv.ticket && this.priv.ticket.modFiles && (this.priv.ticket.modFiles == 1))
			txt += '<b>Документы:</b>{docs:this.formatNull}<br/>';

		return new Ext.Template(txt,
			{
				formatNull: function(value) {
					if (value) {
						return value;
					}
					else return '';
				}
			}
		);
	},
	
	buildInfoTpl10: function(rec) {
		var txt = ''
		txt += '<b>Номер:</b> {id:this.formatNull}<br/>';
		txt += '<b>Тип:</b> {type_text:this.formatNull}<br/>';
		txt += '<b>Дата создания:</b> {date_str:this.formatNull}<br/>';
		txt += '<b>Логист:</b> {logist_login:this.formatNull}<br/>';
		txt += '<b>Дата загрузки:</b> {load_str:this.formatNull}<br/>';
		txt += '<b>Дата выгрузки:</b> {offload_str:this.formatNull}<br/>';
		txt += '<b>Откуда:</b> {fromplace:this.formatNull}<br/>';
		txt += '<b>Куда:</b> {toplace:this.formatNull}<br/>';
		txt += '<b>Менеджер:</b> {manager_login:this.formatNull}<br/>';
		txt += '<b>Тип а/м:</b> {cartype:this.formatNull}<br/>';
		txt += '<b>Описание:</b> {description:this.formatNull}<br/>';
		txt += '<b>Заказчик:</b> {client10:this.formatNull}<br/>';
		txt += '<b>Ставка:</b> {clientprice:this.formatNull}<br/>';
		txt += '<b>Описание груза:</b> {answer:this.formatNull}<br/>';
		txt += '<b>Комментарий:</b> {comment:this.formatNull}<br/>';

		return new Ext.Template(txt,
			{
				formatNull: function(value) {
					if (value) {
						return value;
					}
					else return '';
				}
			}
		);
	},
	
	showMask: function(msg) {
		this.body.mask(msg + '...', 'x-mask-loading');
	},

	hideMask: function() {
		this.body.unmask();
	},
	mapTicketToDeal: function (rec) {
		if (!helpers.types.isObjectAndHasProps(rec)) {
			Ext.Msg.alert('Ошибка', 'Запрос не найден')
			return {}
		}

		return {
			logist: rec.get('logist'),
			manager: rec.get('manager'),
			client: rec.get('client_id'),
			clientperson: rec.get('person_id'),
			load: rec.get('load_str') ? `${rec.get('load_str')} 00:00` : null,
			offload: rec.get('offload_str') ? `${rec.get('offload_str')} 00:00` : null,
			clientfromplace: rec.get('fromplace'),
			clienttoplace: rec.get('toplace'),
			cargo: rec.get('description'),
			client_currency_sum: rec.get('clientprice'),
		}
	},
	createDeal: function (rec) {
		// TODO check hidden: RolesHelper.isMainMenuTabHidden(permissions, RolesHelper.TAB_CRM_NAME)
		const model = this.mapTicketToDeal(rec)
		if (!helpers.types.isObjectAndHasProps(model)) {
			console.error('Ошибка: невозможно создать модель для новой сделки');
			return;
		}

		const clientConfigPromise = new Promise((resolve, reject) => {
			Ext.Ajax.request({
				url: 'index.php',
				params: {
					module: 'configurations',
					method: 'getClientConfig',
					fields: 'showRegistersInContextMenu,transportTypeList,showNbKzCurrencyRatesBlock,docList,transpGridCellColors,gridCellColorsPalette,transpStatusPalette'
				},
				success: function (response) {
					const responseData = Ext.decode(response.responseText)?.data ?? {};
					resolve({
						showRegistersInContextMenu: responseData?.showRegistersInContextMenu === "1",
						transportTypeList: responseData?.transportTypeList ?? [],
						showNbKzCurrencyRatesBlock: responseData?.showNbKzCurrencyRatesBlock === "1",
						docList: responseData?.docList ?? [],
						transpGridCellColors: responseData?.transpGridCellColors ?? {},
						gridCellColorsPalette: responseData?.gridCellColorsPalette ?? {},
						transpStatusPalette: responseData?.transpStatusPalette ?? {},
					});
				},
				failure: function (response) {
					reject(`Ошибка при загрузке конфигурации: ${response.responseText}`);
				}
			});
		});

		const transportationDataPromise = new Promise((resolve, reject) => {
			Ext.Ajax.request({
				url: 'index.php',
				params: {
					module: 'transportation3',
					method: 'onOpen',
				},
				success: function (response) {
					resolve(response);
				},
				failure: function (response) {
					reject(`Ошибка при загрузке transportation3: ${response.responseText}`);
				}
			});
		});

		// Ждём, пока оба запроса завершатся
		Promise.all([clientConfigPromise, transportationDataPromise])
			.then(([clientConfig, transportationData]) => {
				console.log("✅ Оба запроса завершены, обновляем интерфейс");
				if (kDesktop.app.mainPanel && kDesktop.app.mainPanel.items.length > 0) {

					if (this.grid) {
						this.grid.un('itemcontextmenu', this.someHandler)
						this.grid.un('containercontextmenu', this.someHandler)
						this.grid.un('select', this.someHandler)
						this.grid.destroy()
					}

					if (this.grid10) {
						this.grid10.un('itemcontextmenu', this.someHandler)
						this.grid10.un('containercontextmenu', this.someHandler)
						this.grid10.un('select', this.someHandler)
						this.grid10.destroy()
					}

					if (this.store) {
						this.store.removeAll()
						this.store.destroy()
						this.store = null
					}

					if (this.store10) {
						this.store10.removeAll()
						this.store10.destroy()
						this.store10 = null
					}

					// Убираем всё из mainPanel
					kDesktop.app.mainPanel.suspendEvents()
					kDesktop.app.mainPanel.removeAll(true)
					kDesktop.app.mainPanel.resumeEvents()
				}
				/*kDesktop.app.mainPanel.add(
					Ext.create('kDesktop.transportation3', {
						app: kDesktop.app,
						data: transportationData,
						clientConfig,
						newDealModel: model,
					})
				);*/

			})
			.catch((error) => {
				console.error(error);
			});

		/*Ext.Ajax.request({
			url: 'index.php',
			params: {
				module: 'configurations',
				method: 'getClientConfig',
				fields: 'showRegistersInContextMenu,transportTypeList,showNbKzCurrencyRatesBlock,docList,transpGridCellColors,gridCellColorsPalette,transpStatusPalette'
			},
			success: function (response) {
				const responseData = Ext.decode(response.responseText)?.data ?? {}
				const clientConfig = {
					showRegistersInContextMenu: responseData?.showRegistersInContextMenu === "1",
					transportTypeList: responseData?.transportTypeList ?? [],
					showNbKzCurrencyRatesBlock: responseData?.showNbKzCurrencyRatesBlock === "1",
					docList: responseData?.docList ?? [],
					transpGridCellColors: responseData?.transpGridCellColors ?? {},
					gridCellColorsPalette: responseData?.gridCellColorsPalette ?? {},
					transpStatusPalette: responseData?.transpStatusPalette ?? {},
				}

				Ext.Ajax.request({
					url: 'index.php',
					params: {
						module: 'transportation3',
						method: 'onOpen',
					},
					success: function (response) {
						console.log('response', response);
						if (kDesktop.app.mainPanel && kDesktop.app.mainPanel.items.length > 0) {
							kDesktop.app.mainPanel.suspendEvents();
							kDesktop.app.mainPanel.removeAll(true);
						}

						kDesktop.app.mainPanel.add(
							Ext.create('kDesktop.transportation3', {
								app: kDesktop.app,
								data: response,
								clientConfig,
								newDealModel: model,
							})
						)
						kDesktop.app.mainPanel.resumeEvents();
					},
					failure: function (response) {
						const responseText = response.responseText;
						console.log('Getting ClientConfig error:', responseText)
					},
				})
			},
			failure: function (response) {
				const responseText = response.responseText;
				console.log('Getting ClientConfig error:', responseText)
			},
		})*/
	}
})

Ext.define('kDesktop.ticket2.addTicketWnd', {
	extend: 'Ext.window.Window',
	constructor: function(config) {
		config = config || {};
		
		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.data = config.data;
		
		this.clientPersonStore = Ext.create('Ext.data.JsonStore', {
			autoSave: false,
			autoLoad: false,
			proxy: {
				actionMethods: 'POST',
				type: 'ajax',
				url: this.ownerModule.app.connectUrl,
				extraParams: {
					module: this.ownerModule.moduleId,
					method: 'clientPersonList'
				},
				reader: {
					type: 'json',
					root: 'items',
					totalProperty: 'totalCount'
				}
			},
			fields: ['id', 'name'],
			idproperty: 'id',
			totalProperty: 'totalCount'
		})
		
		this.mainForm = Ext.create('Ext.form.Panel', {
			border: false,
			frame: true,
			bodyStyle:'padding:5px;',
			items: [
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Тип'},
						{
							xtype: 'combobox',
							width: 250,
							name: 'type',
							ref: 'typeCmb',
							queryMode: 'local',
							displayField: 'value',
							valueField: 'key',
							editable: false,
							store: Ext.create('Ext.data.ArrayStore', { fields: [ 'key', 'value' ] })
						}
					]					
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Клиент'},
						{
							xtype: 'textfield',
							width: 250,
							ref: 'clientNameFld',
							readOnly: true,
							allowBlank: false
						},
						{
							xtype: 'button',
							text: 'Выбрать',
							//iconCls: 'ok-icon',
							scope: this,
							handler: function(){
								Ext.create('kDesktop.ticket2.chooseClientWnd', { ownerModule: this.ownerModule, parent: this }).show();
							}
						},
						{
							xtype: 'button',
							text: 'Создать',
							//iconCls: 'ok-icon',
							scope: this,
							handler: function(){
								Ext.create('kDesktop.ticket2.fastAddClientWnd', { ownerModule: this.ownerModule, parent: this }).show();
							}
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Контактное лицо'},
						{
							xtype: 'combobox',
							width: 250,
							name: 'client_person',
							ref: 'clientPersonCmb',
							queryMode: 'remote',
							pageSize: 40,
							displayField: 'name',
							valueField: 'id',
							typeAhead: true,
							minChars: 2,
							allowBlank: true,
							editable: false,
							store: this.clientPersonStore
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Направление'},
						{
							xtype: 'combobox',
							width: 250,
							name: 'fromplace',
							ref: 'fromPlaceCmb',
							queryMode: 'remote',
							pageSize: 40,
							displayField: 'place',
							valueField: 'id',
							typeAhead: true,
							minChars: 2,
							allowBlank: false,
							store: Ext.create('Ext.data.JsonStore', {
								autoSave: false,
								proxy: {
									actionMethods: 'POST',
									type: 'ajax',
									url: this.ownerModule.app.connectUrl,
									extraParams: {
										module: this.ownerModule.moduleId,
										method: 'ticketPlace'
									},
									reader: {
										type: 'json',
										root: 'items',
										totalProperty: 'totalCount'
									}
								},
								fields: ['id', 'place'],
								idproperty: 'id',
								totalProperty: 'totalCount'
							})
						},
						{
							xtype: 'combobox',
							width: 250,
							name: 'toplace',
							ref: 'toPlaceCmb',
							queryMode: 'remote',
							pageSize: 40,
							displayField: 'place',
							valueField: 'id',
							typeAhead: true,
							minChars: 2,
							allowBlank: false,
							store: Ext.create('Ext.data.JsonStore', {
								autoSave: false,
								proxy: {
									actionMethods: 'POST',
									type: 'ajax',
									url: this.ownerModule.app.connectUrl,
									extraParams: {
										module: this.ownerModule.moduleId,
										method: 'ticketPlace'
									},
									reader: {
										type: 'json',
										root: 'items',
										totalProperty: 'totalCount'
									}
								},
								fields: ['id', 'place'],
								idproperty: 'id',
								totalProperty: 'totalCount'
							})
						}							
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Дата загрузки/выгрузки'},
						{
							xtype: 'datefield',
							width: 100,
							allowBlank: true,
							format: 'd.m.Y',
							editable: false,
							startDay: 1,
							name: 'loaddate'
						},
						{
							xtype: 'datefield',
							width: 100,
							allowBlank: true,
							format: 'd.m.Y',
							editable: false,
							startDay: 1,
							name: 'offloaddate'
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Тип а/м'},
						{
							xtype: 'combobox',
							width: 250,
							name: 'cartype',
							ref: 'cartypeCmb',
							queryMode: 'local',
							displayField: 'value',
							valueField: 'key',
							store: Ext.create('Ext.data.ArrayStore', { fields: [ 'key', 'value' ] })
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Описание, характер груза, объем, вес'},
						{
							xtype: 'textarea',
							width: 500,
							height: 60,
							name: 'description'
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Время на выполнение задачи'},
						{
							xtype: 'datefield',
							width: 100,
							allowBlank: true,
							format: 'd.m.Y',
							editable: false,
							startDay: 1,
							name: 'date_exec',
							minValue: new Date()
						},
						{
							xtype: 'numberfield',
							width: 50,
							value: 0,
							name: 'date_exec_hour',
							minValue: 0,
							maxValue: 23,
							decimalPrecision: 0
						},
						{
							xtype: 'numberfield',
							width: 50,
							value: 0,
							name: 'date_exec_min',
							minValue: 0,
							maxValue: 59,
							decimalPrecision: 0
						}
					]
				},
				{
					xtype : 'container',
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
				}
			]
		});
		this.ownerModule.app.createReference(this.mainForm);
			     	
		Ext.applyIf(config, {
			title: 'Добавление запроса',
			width: 705,
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
					text: 'Закрыть',
					iconCls: 'close-icon',
					scope: this,
					handler: function(){
						this.close();
					}
				}
			]
		});

		kDesktop.ticket2.addTicketWnd.superclass.constructor.call(this, config);
		
		this.on('afterrender', function() {
			if (this.data) {
				if (this.data.dictTicketType) {
					this.mainForm.typeCmb.store.loadData(this.data.dictTicketType);
					this.mainForm.typeCmb.select(1);
				}
				
				if (this.data.dictTicketCarType) {
					this.mainForm.cartypeCmb.store.loadData(this.data.dictTicketCarType);
					this.mainForm.cartypeCmb.select(1);
				}
			}
		}, this);
	},
	
	save: function () {
		if (this.mainForm.getForm().isValid()) {
			Ext.MessageBox.confirm('Сохранение', 'Вы уверены что хотите сохранить эту запись?',
				function(btn){
					if(btn == 'yes') {
						var data = this.ownerModule.app.getFormValues(this.mainForm);
						data.client = this.data.client;
						data.fromplace = this.mainForm.fromPlaceCmb.getRawValue();
						data.toplace = this.mainForm.toPlaceCmb.getRawValue();
						data.cartype = this.mainForm.cartypeCmb.getRawValue();

						this.mainForm.getForm().submit({
							url: this.ownerModule.app.connectUrl,
							scope: this,
							params: {
								module: this.ownerModule.moduleId,
								method: 'addTicket',
								data: Ext.encode(data)
							},
							waitMsg: 'Сохраняется...',
							success: function(form, action) {
								this.parent.store.load();
								this.close();
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

Ext.define('kDesktop.ticket2.addTicket10Wnd', {
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
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: ''},
						{
							xtype: 'textfield',
							width: 500,
							name: 'description10'
						}
					]	
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Направление'},
						{
							xtype: 'combobox',
							width: 250,
							name: 'fromplace',
							ref: 'fromPlaceCmb',
							queryMode: 'remote',
							pageSize: 40,
							displayField: 'place',
							valueField: 'id',
							typeAhead: true,
							minChars: 2,
							allowBlank: false,
							store: Ext.create('Ext.data.JsonStore', {
								autoSave: false,
								proxy: {
									actionMethods: 'POST',
									type: 'ajax',
									url: this.ownerModule.app.connectUrl,
									extraParams: {
										module: this.ownerModule.moduleId,
										method: 'ticketPlace'
									},
									reader: {
										type: 'json',
										root: 'items',
										totalProperty: 'totalCount'
									}
								},
								fields: ['id', 'place'],
								idproperty: 'id',
								totalProperty: 'totalCount'
							})
						},
						{
							xtype: 'combobox',
							width: 250,
							name: 'toplace',
							ref: 'toPlaceCmb',
							queryMode: 'remote',
							pageSize: 40,
							displayField: 'place',
							valueField: 'id',
							typeAhead: true,
							minChars: 2,
							allowBlank: false,
							store: Ext.create('Ext.data.JsonStore', {
								autoSave: false,
								proxy: {
									actionMethods: 'POST',
									type: 'ajax',
									url: this.ownerModule.app.connectUrl,
									extraParams: {
										module: this.ownerModule.moduleId,
										method: 'ticketPlace'
									},
									reader: {
										type: 'json',
										root: 'items',
										totalProperty: 'totalCount'
									}
								},
								fields: ['id', 'place'],
								idproperty: 'id',
								totalProperty: 'totalCount'
							})
						}							
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Дата загрузки/выгрузки'},
						{
							xtype: 'datefield',
							width: 100,
							allowBlank: true,
							format: 'd.m.Y',
							editable: false,
							startDay: 1,
							name: 'loaddate'
						},
						{
							xtype: 'datefield',
							width: 100,
							allowBlank: true,
							format: 'd.m.Y',
							editable: false,
							startDay: 1,
							name: 'offloaddate'
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Тип а/м'},
						{
							xtype: 'combobox',
							width: 250,
							name: 'cartype',
							ref: 'cartypeCmb',
							queryMode: 'local',
							displayField: 'value',
							valueField: 'key',
							store: Ext.create('Ext.data.ArrayStore', { fields: [ 'key', 'value' ] })
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Описание'},
						{
							xtype: 'textarea',
							width: 500,
							height: 60,
							name: 'description'
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Время на выполнение задачи'},
						{
							xtype: 'datefield',
							width: 100,
							allowBlank: true,
							format: 'd.m.Y',
							editable: false,
							startDay: 1,
							name: 'date_exec',
							minValue: new Date()
						},
						{
							xtype: 'numberfield',
							width: 50,
							value: 0,
							name: 'date_exec_hour',
							minValue: 0,
							maxValue: 23,
							decimalPrecision: 0
						},
						{
							xtype: 'numberfield',
							width: 50,
							value: 0,
							name: 'date_exec_min',
							minValue: 0,
							maxValue: 59,
							decimalPrecision: 0
						}
					]
				}
			]
		});
		this.ownerModule.app.createReference(this.mainForm);
			     	
		Ext.applyIf(config, {
			title: 'Добавление запроса',
			width: 705,
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
					text: 'Закрыть',
					iconCls: 'close-icon',
					scope: this,
					handler: function(){
						this.close();
					}
				}
			]
		});

		kDesktop.ticket2.addTicket10Wnd.superclass.constructor.call(this, config);
		
		this.on('afterrender', function() {
			if (this.data) {
				if (this.data.dictTicketCarType) {
					this.mainForm.cartypeCmb.store.loadData(this.data.dictTicketCarType);
					this.mainForm.cartypeCmb.select(1);
				}
			}
		}, this);
	},
	
	save: function () {
		if (this.mainForm.getForm().isValid()) {
			Ext.MessageBox.confirm('Сохранение', 'Вы уверены что хотите сохранить эту запись?',
				function(btn){
					if(btn == 'yes') {
						var data = this.ownerModule.app.getFormValues(this.mainForm);
						data.fromplace = this.mainForm.fromPlaceCmb.getRawValue();
						data.toplace = this.mainForm.toPlaceCmb.getRawValue();
						data.cartype = this.mainForm.cartypeCmb.getRawValue();

						this.ownerModule.app.doAjax({
							module: this.ownerModule.moduleId,
							method: 'addTicket10',
							data: Ext.encode(data)
						},
						function(res) {
							this.parent.store10.load();
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

Ext.define('kDesktop.ticket2.editTicketWnd', {
	extend: 'Ext.window.Window',
	constructor: function(config) {
		config = config || {};
		
		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.data = config.data;
		
		this.clientPersonStore = Ext.create('Ext.data.JsonStore', {
			autoSave: false,
			autoLoad: true,
			proxy: {
				actionMethods: 'POST',
				type: 'ajax',
				url: this.ownerModule.app.connectUrl,
				extraParams: {
					module: this.ownerModule.moduleId,
					method: 'clientPersonList',
					pid: this.data.data.client
				},
				reader: {
					type: 'json',
					root: 'items',
					totalProperty: 'totalCount'
				}
			},
			fields: ['id', 'name'],
			idproperty: 'id',
			totalProperty: 'totalCount'
		})
		
		this.mainForm = Ext.create('Ext.form.Panel', {
			border: false,
			frame: true,
			bodyStyle:'padding:5px;',
			items: [
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Тип'},
						{
							xtype: 'combobox',
							width: 250,
							name: 'type',
							ref: 'typeCmb',
							queryMode: 'local',
							displayField: 'value',
							valueField: 'key',
							editable: false,
							store: Ext.create('Ext.data.ArrayStore', { fields: [ 'key', 'value' ] })
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Клиент'},
						{
							xtype: 'textfield',
							width: 250,
							ref: 'clientNameFld',
							name: 'client_name',
							readOnly: true,
							allowBlank: false
						},
						{
							xtype: 'button',
							text: 'Выбрать',
							//iconCls: 'ok-icon',
							scope: this,
							handler: function(){
								Ext.create('kDesktop.ticket2.chooseClientWnd', { ownerModule: this.ownerModule, parent: this }).show();
							}
						},
						{
							xtype: 'button',
							text: 'Создать',
							//iconCls: 'ok-icon',
							scope: this,
							handler: function(){
								Ext.create('kDesktop.ticket2.fastAddClientWnd', { ownerModule: this.ownerModule, parent: this }).show();
							}
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Контактное лицо'},
						{
							xtype: 'combobox',
							width: 250,
							name: 'client_person',
							ref: 'clientPersonCmb',
							queryMode: 'remote',
							pageSize: 40,
							displayField: 'name',
							valueField: 'id',
							typeAhead: true,
							minChars: 2,
							allowBlank: true,
							editable: false,
							store: this.clientPersonStore
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Направление'},
						{
							xtype: 'combobox',
							width: 250,
							name: 'fromplace',
							ref: 'fromPlaceCmb',
							queryMode: 'remote',
							pageSize: 40,
							displayField: 'place',
							valueField: 'id',
							typeAhead: true,
							minChars: 2,
							allowBlank: false,
							store: Ext.create('Ext.data.JsonStore', {
								autoSave: false,
								proxy: {
									actionMethods: 'POST',
									type: 'ajax',
									url: this.ownerModule.app.connectUrl,
									extraParams: {
										module: this.ownerModule.moduleId,
										method: 'ticketPlace'
									},
									reader: {
										type: 'json',
										root: 'items',
										totalProperty: 'totalCount'
									}
								},
								fields: ['id', 'place'],
								idproperty: 'id',
								totalProperty: 'totalCount'
							})
						},
						{
							xtype: 'combobox',
							width: 250,
							name: 'toplace',
							ref: 'toPlaceCmb',
							queryMode: 'remote',
							pageSize: 40,
							displayField: 'place',
							valueField: 'id',
							typeAhead: true,
							minChars: 2,
							allowBlank: false,
							store: Ext.create('Ext.data.JsonStore', {
								autoSave: false,
								proxy: {
									actionMethods: 'POST',
									type: 'ajax',
									url: this.ownerModule.app.connectUrl,
									extraParams: {
										module: this.ownerModule.moduleId,
										method: 'ticketPlace'
									},
									reader: {
										type: 'json',
										root: 'items',
										totalProperty: 'totalCount'
									}
								},
								fields: ['id', 'place'],
								idproperty: 'id',
								totalProperty: 'totalCount'
							})
						}							
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Дата загрузки/выгрузки'},
						{
							xtype: 'datefield',
							width: 100,
							allowBlank: true,
							format: 'd.m.Y',
							editable: false,
							startDay: 1,
							name: 'loaddate'
						},
						{
							xtype: 'datefield',
							width: 100,
							allowBlank: true,
							format: 'd.m.Y',
							editable: false,
							startDay: 1,
							name: 'offloaddate'
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Тип а/м'},
						{
							xtype: 'combobox',
							width: 250,
							name: 'cartype',
							ref: 'cartypeCmb',
							queryMode: 'local',
							displayField: 'value',
							valueField: 'key',
							store: Ext.create('Ext.data.ArrayStore', { fields: [ 'key', 'value' ] })
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Описание, характер груза, объем, вес'},
						{
							xtype: 'textarea',
							width: 500,
							height: 60,
							name: 'description'
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Цена'},
						{
							xtype: 'numberfield',
							width: 250,
							value: 0,
							name: 'price',
							minValue: 0,
							decimalPrecision: 2,
							hideTrigger:true
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Ответ'},
						{
							xtype: 'textarea',
							width: 500,
							height: 60,
							name: 'answer'
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Цена для клиента'},
						{
							xtype: 'numberfield',
							width: 250,
							value: 0,
							name: 'clientprice',
							minValue: 0,
							decimalPrecision: 2,
							hideTrigger:true
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Комментарий'},
						{
							xtype: 'textarea',
							width: 500,
							height: 60,
							allowBlank: true,
							name: 'comment'
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Комментарий для клиента'},
						{
							xtype: 'textarea',
							width: 500,
							height: 60,
							allowBlank: true,
							name: 'clientcomment'
						}
					]
				}
			]
		});
		this.ownerModule.app.createReference(this.mainForm);
			     	
		Ext.applyIf(config, {
			title: 'Редактирование запроса',
			width: 705,
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
					text: 'Закрыть',
					iconCls: 'close-icon',
					scope: this,
					handler: function(){
						this.close();
					}
				}
			]
		});

		kDesktop.ticket2.editTicketWnd.superclass.constructor.call(this, config);
		
		this.on('afterrender', function() {
			if (this.data) {
				if (this.data.dictTicketType) this.mainForm.typeCmb.store.loadData(this.data.dictTicketType);
				if (this.data.dictTicketCarType) this.mainForm.cartypeCmb.store.loadData(this.data.dictTicketCarType);
				if (this.data.data) {
					this.mainForm.getForm().setValues(this.data.data);
					this.data.id = this.data.data.id;
					this.data.client = this.data.data.client;
				}
			}
		}, this);
	},
	
	save: function () {
		if (this.mainForm.getForm().isValid()) {
			Ext.MessageBox.confirm('Сохранение', 'Вы уверены что хотите сохранить эту запись?',
				function(btn){
					if(btn == 'yes') {
						var data = this.ownerModule.app.getFormValues(this.mainForm);
						data.client = this.data.client;
						data.fromplace = this.mainForm.fromPlaceCmb.getRawValue();
						data.toplace = this.mainForm.toPlaceCmb.getRawValue();
						data.cartype = this.mainForm.cartypeCmb.getRawValue();

						this.ownerModule.app.doAjax({
							module: this.ownerModule.moduleId,
							method: 'editTicket',
							id: this.data.id,
							data: Ext.encode(data)
						},
						function(res) {
							this.parent.store.load();
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

Ext.define('kDesktop.ticket2.fastAddClientWnd', {
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
					layout: { type: 'hbox' },
					items: [
						{xtype: 'displayfield', width: 150, value: 'Наименование'},
						{
							xtype: 'textfield',
							flex: 1,
							name: 'name',
							allowBlank: false
						}
					]
				},
				{
					xtype : 'container',
					layout: { type: 'hbox' },
					items: [
						{xtype: 'displayfield', width: 150, value: 'Контактные данные'},
						{
							xtype: 'textfield',
							flex: 1,
							name: 'contacts',
							allowBlank: false
						}
					]
				},
				{
					xtype : 'container',
					layout: { type: 'hbox' },
					items: [
						{xtype: 'displayfield', width: 150, value: 'Телефон'},
						{
							xtype: 'textfield',
							flex: 1,
							name: 'phone',
							allowBlank: false
						}
					]
				}
			]
		});
		this.ownerModule.app.createReference(this.mainForm);
			     	
		Ext.applyIf(config, {
			title: 'Новый клиент',
			width: 400,
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
					text: 'Закрыть',
					iconCls: 'close-icon',
					scope: this,
					handler: function(){
						this.close();
					}
				}
			]
		});

		kDesktop.ticket2.fastAddClientWnd.superclass.constructor.call(this, config);
	},
	
	save: function () {
		if (this.mainForm.getForm().isValid()) {
			Ext.MessageBox.confirm('Сохранение', 'Вы уверены что хотите сохранить эту запись?',
				function(btn){
					if(btn == 'yes') {
						var data = this.ownerModule.app.getFormValues(this.mainForm);
						this.ownerModule.app.doAjax({
							module: this.ownerModule.moduleId,
							method: 'fastAddClient',
							data: Ext.encode(data)
						},
						function(res) {
							this.parent.data.client = res.id;
							this.parent.mainForm.clientNameFld.setValue(res.name);
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

Ext.define('kDesktop.ticket2.chooseClientWnd', {
	extend: 'Ext.window.Window',
	constructor: function(config) {
		config = config || {};

		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		
		this.clientStore = Ext.create('Ext.data.JsonStore', {
			pageSize: 100,
			root: 'items',
			idProperty: 'id',
			remoteSort: true,
			autoLoad: true,
			fields: [
				'id',
				'name',
				'fullname',
				'inn'
			],
			proxy: {
				actionMethods: 'POST',
				type: 'ajax',
				url: this.ownerModule.app.connectUrl,
				extraParams: {
					module: this.ownerModule.moduleId,
					method: 'allClientList'
				},
				reader: {
					type: 'json',
					root: 'items',
					totalProperty: 'totalCount'
				}
			},
			sorters: [{
				property: 'name',
				direction: 'ASC'
			}]
		});
		
		this.clientGridTbar = Ext.create('Ext.form.Panel', {
			border: false,
			frame: true,
			layout: 'fit',
			items:[
				{
					xtype: 'textfield',
					maxLength: 255,
					name: 'search',
					ref: 'searchFld',
					listeners: {
						change: {
							fn: function( obj, newValue, oldValue, eOpts ) {
								this.clientStore.proxy.extraParams.search = newValue;
								this.clientGridBbar.moveFirst();
							},
							scope: this
						}
					}
				}
			]
		});
		this.ownerModule.app.createReference(this.topPnl);
		
		this.clientGridBbar = Ext.create('Ext.toolbar.Paging', {
			store: this.clientStore,
			displayInfo: true,
			displayMsg: 'Записи {0} - {1} из {2}',
			emptyMsg: "Нет записей"
		});

		this.clientGrid = Ext.create('Ext.grid.Panel', {
			store: this.clientStore,
			loadMask: true,
			columnLines: true,
			flex: 1,
			columns:[
				{
					header: "Наименование",
					dataIndex: 'name',
					width: 200,
					sortable: true
				},
				{
					header: "ИНН",
					dataIndex: 'inn',
					width: 200,
					sortable: true
				},
				{
					header: "Полное наименование",
					dataIndex: 'fullname',
					width: 200,
					sortable: true
				}
			],
			viewConfig: {
				stripeRows: true
			},
			tbar: this.clientGridTbar,
			bbar: this.clientGridBbar
		});
		this.clientGrid.on('itemdblclick', function(view, rec, item, index, eventObj, options) {
			this.addClient(rec);
		}, this);
		this.clientGrid.on('itemcontextmenu',function(view, rec, node, index, eventObj) {
			var _contextMenu = Ext.create('Ext.menu.Menu', {
				items: [
					{
						text: 'Выбрать',
						iconCls: 'add-icon',
						scope: this,
						handler: function (){
							this.addClient(rec);
						}
					}
				]
			});
			_contextMenu.showAt(eventObj.getXY());
			eventObj.stopEvent();
		}, this);
			     	
		Ext.applyIf(config, {
			title: 'Клиент',
			width: 680,
			height: 300,
			modal: true,
			plain: true,
			border: false,
			layout: 'fit',
			items: [
				this.clientGrid
			],
			buttons: [
				{
					text: 'Выбрать',
					iconCls: 'ok-icon',
					scope: this,
					handler: function(){
						this.addClient(null);
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

		kDesktop.ticket2.chooseClientWnd.superclass.constructor.call(this, config);		
	},
	
	addClient: function (rec) {
		if (!rec) {
			rec = this.clientGrid.getSelectionModel().getSelection();
			rec = rec[0];
		}

		if (rec) {
			this.parent.data.client = rec.get('id');
			this.parent.mainForm.clientNameFld.setValue(rec.get('name'));
			
			//cmb3.enable();
			this.parent.mainForm.clientPersonCmb.reset();
			this.parent.mainForm.clientPersonCmb.store.removeAll();
			this.parent.mainForm.clientPersonCmb.lastQuery = null;
			this.parent.mainForm.clientPersonCmb.setValue();
			this.parent.clientPersonStore.proxy.extraParams.pid = rec.get('id');
			this.parent.clientPersonStore.load();
			this.parent.mainForm.clientPersonCmb.bindStore(this.parent.clientPersonStore);
			
			this.close();
		}
	},
	
	showMask: function(msg) {
		this.body.mask(msg + '...', 'x-mask-loading');
	},

	hideMask: function() {
		this.body.unmask();
	}
});

Ext.define('kDesktop.ticket2.closeTicketWnd', {
	extend: 'Ext.window.Window',
	constructor: function(config) {
		config = config || {};
		
		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.ticket_id = config.ticket_id;
		
		this.mainForm = Ext.create('Ext.form.Panel', {
			border: false,
			frame: true,
			bodyStyle:'padding:5px;',
			items: [
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Цена'},
						{
							xtype: 'numberfield',
							width: 250,
							value: 0,
							name: 'price',
							minValue: 0,
							decimalPrecision: 2,
							hideTrigger:true
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Ответ'},
						{
							xtype: 'textarea',
							width: 500,
							height: 60,
							allowBlank: false,
							name: 'answer'
						}
					]
				}
			]
		});
		this.ownerModule.app.createReference(this.mainForm);
			     	
		Ext.applyIf(config, {
			title: 'Закрытие запроса',
			width: 705,
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

		kDesktop.ticket2.closeTicketWnd.superclass.constructor.call(this, config);
	},
	
	save: function () {
		if (this.mainForm.getForm().isValid()) {
			Ext.MessageBox.confirm('Сохранение', 'Вы уверены что хотите сохранить эту запись?',
				function(btn){
					if(btn == 'yes') {
						var data = this.ownerModule.app.getFormValues(this.mainForm);

						this.ownerModule.app.doAjax({
							module: this.ownerModule.moduleId,
							method: 'closeTicket',
							id: this.ticket_id,
							data: Ext.encode(data)
						},
						function(res) {
							this.parent.gridBbar.doRefresh();
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

Ext.define('kDesktop.ticket2.closeTicket10Wnd', {
	extend: 'Ext.window.Window',
	constructor: function(config) {
		config = config || {};
		
		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.ticket_id = config.ticket_id;
		
		this.mainForm = Ext.create('Ext.form.Panel', {
			border: false,
			frame: true,
			bodyStyle:'padding:5px;',
			items: [
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Заказчик'},
						{
							xtype: 'textfield',
							width: 500,
							allowBlank: false,
							name: 'client10'
						}
					]	
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Дата загрузки/выгрузки'},
						{
							xtype: 'datefield',
							width: 100,
							allowBlank: false,
							format: 'd.m.Y',
							editable: false,
							startDay: 1,
							name: 'loaddate'
						},
						{
							xtype: 'datefield',
							width: 100,
							allowBlank: false,
							format: 'd.m.Y',
							editable: false,
							startDay: 1,
							name: 'offloaddate'
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Ставка'},
						{
							xtype: 'numberfield',
							width: 250,
							value: 0,
							name: 'clientprice',
							minValue: 0,
							decimalPrecision: 2,
							hideTrigger:true
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Описание груза'},
						{
							xtype: 'textarea',
							width: 500,
							height: 60,
							allowBlank: false,
							name: 'answer'
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Комментарий'},
						{
							xtype: 'textarea',
							width: 500,
							height: 60,
							allowBlank: false,
							name: 'comment'
						}
					]
				}
			]
		});
		this.ownerModule.app.createReference(this.mainForm);
			     	
		Ext.applyIf(config, {
			title: 'Закрытие запроса',
			width: 705,
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

		kDesktop.ticket2.closeTicket10Wnd.superclass.constructor.call(this, config);
	},
	
	save: function () {
		if (this.mainForm.getForm().isValid()) {
			Ext.MessageBox.confirm('Сохранение', 'Вы уверены что хотите сохранить эту запись?',
				function(btn){
					if(btn == 'yes') {
						var data = this.ownerModule.app.getFormValues(this.mainForm);

						this.ownerModule.app.doAjax({
							module: this.ownerModule.moduleId,
							method: 'closeTicket10',
							id: this.ticket_id,
							data: Ext.encode(data)
						},
						function(res) {
							this.parent.gridBbar10.doRefresh();
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

Ext.define('kDesktop.ticket2.setTicketClientPriceWnd', {
	extend: 'Ext.window.Window',
	constructor: function(config) {
		config = config || {};
		
		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		this.ticket_id = config.ticket_id;
		
		this.mainForm = Ext.create('Ext.form.Panel', {
			border: false,
			frame: true,
			bodyStyle:'padding:5px;',
			items: [
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Цена'},
						{
							xtype: 'numberfield',
							width: 250,
							value: 0,
							name: 'price',
							minValue: 0,
							decimalPrecision: 2,
							hideTrigger:true
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Комментарий'},
						{
							xtype: 'textarea',
							width: 500,
							height: 60,
							allowBlank: true,
							name: 'comment'
						}
					]
				},
				{
					xtype : 'container',
					layout: 'hbox',
					items: [
						{xtype: 'displayfield', width: 170, value: 'Комментарий для клиента'},
						{
							xtype: 'textarea',
							width: 500,
							height: 60,
							allowBlank: true,
							name: 'clientcomment'
						}
					]
				}
			]
		});
		this.ownerModule.app.createReference(this.mainForm);
			     	
		Ext.applyIf(config, {
			title: 'Стоимость для клиента',
			width: 705,
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

		kDesktop.ticket2.setTicketClientPriceWnd.superclass.constructor.call(this, config);
	},
	
	save: function () {
		if (this.mainForm.getForm().isValid()) {
			Ext.MessageBox.confirm('Сохранение', 'Вы уверены что хотите сохранить эту запись?',
				function(btn){
					if(btn == 'yes') {
						var data = this.ownerModule.app.getFormValues(this.mainForm);

						this.ownerModule.app.doAjax({
							module: this.ownerModule.moduleId,
							method: 'setTicketClientPrice',
							id: this.ticket_id,
							data: Ext.encode(data)
						},
						function(res) {
							this.parent.gridBbar.doRefresh();
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

Ext.define('kDesktop.ticket2.setTicketClientPriceGridWnd', {
	extend: 'Ext.window.Window',
	moduleId: 'ticket2',
	constructor: function(config) {
		config = config || {};
		
		this.app = config.app;

		this.panel = Ext.create('kDesktop.ticket2.tickets', {
			ownerModule: this,
			parent: this,
			awaitingClientPrice: true,
			data: {
				priv: config?.priv ?? {},
				permissions: config?.permissions ?? {},
			}
		});
		this.panel.store.proxy.extraParams.showTicketAwaitingClientPrice = 1;
			     	
		Ext.applyIf(config, {
			title: 'Стоимость для клиента',
			width: 900,
			height: 500,
			modal: true,
			plain: true,
			border: false,
			maximizable: true,
			layout: 'fit',
			items: [
				this.panel
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
			],
			listeners: {
				beforeclose: function(panel, eOpts) { 
					Ext.setTicketClientPriceGridWndShown = false;
				}
			}
		});
	
		kDesktop.ticket2.setTicketClientPriceWnd.superclass.constructor.call(this, config);
		
		Ext.setTicketClientPriceGridWndShown = true;
	},
	
	showMask: function(msg) {
		this.body.mask(msg + '...', 'x-mask-loading');
	},

	hideMask: function() {
		this.body.unmask();
	}
});
