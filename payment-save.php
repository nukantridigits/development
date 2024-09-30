Ext.define('kDesktop.orderimport.main', {
	extend: 'Ext.panel.Panel',
	constructor: function(config) {
		config = config || {};
		
		this.ownerModule = config.ownerModule;
		this.parent = config.parent;
		
		this.uid = 'orderimport-main';
		this.title = 'Импорт выписок';
		this.closable = false;

		this.selected = null;
		this.scroll = 0;
		this.currentPage = 0;
		this.store = Ext.create('Ext.data.Store', {
			pageSize: 100,
			root: 'items',
			idProperty: 'id',
			remoteSort: true,
			autoLoad: true,
			fields: [
				'id',
				'rownum',
				'tid',
				'source',
				'currency',
				'currency_rate',
				'invalue',
				'outvalue',
				'inouttype',
				'inoutstr',
				'contragent',
				'contr_inn',
				'contr_name',
				'billnum',
				'billtid',
				'payorderdate_str',
				'ordersns',
				'tms_contr_out',
				'tms_contr_in',
				'tms_contr_in_sum',
				'tms_contr_in_currency',
				'tms_contr_in_currency_rate',
				
				'tms_contr_out_sum',
				'tms_contr_out_currency',
				'tms_contr_out_currency_rate',
				{name: 'status', type: 'int'},

				{name: 'error', type: 'int'},
				{name: 'errorCurrency', type: 'int'},
				{name: 'errorContragent', type: 'int'},
				{name: 'errorSum', type: 'int'}
			],
			proxy: {
				actionMethods: 'POST',
				type: 'ajax',
				url: this.ownerModule.app.connectUrl,
				extraParams: {
					module: this.ownerModule.moduleId,
					method: 'ordersGrid'
				},
				reader: {
					type: 'json',
					root: 'items',
					totalProperty: 'totalCount'
				}
			},
			sorters: [{
				property: 'status_text',
				direction: 'ASC'
			}]
		});
		this.store.on('beforeload', function(){
			try {
				this.scroll = this.grid.getView().el.dom.scrollTop;
			} catch(e) {}

		},this);
		this.store.on('load', function() { 
			if ( (this.store.getTotalCount() > 0) && this.grid && this.selected ) {
				var rec = this.store.findRecord('id', this.selected.get('id'));
				
				try {
					var index = rec.index - this.store.pageSize*(this.currentPage-1);
					this.grid.getSelectionModel().select(index, true, true);
				} catch(e) {
					this.selected = null;
					this.scroll = 0;
				}
			}
			
			new Ext.util.DelayedTask(function() {
				this.grid.setScrollTop(this.scroll);
			}, this).delay(300);
		},this);
		
		this.gridTbar = Ext.create('Ext.toolbar.Toolbar', {
			items: [
				{
					text: 'Действия',
					menu:[
						{
							text: 'Применить',
							handler: function (){
								Ext.MessageBox.confirm('Применение', 'Вы уверены что хотите применить эти платежи?',
									function(btn){
										if(btn == 'yes') {
											this.ownerModule.app.doAjax({
												module: this.ownerModule.moduleId,
												method: 'applyAll'
											},
											function(res) {
													this.store.load();
											},
											this, this);
										}
									},
									this
								);
							},
							scope: this
						},
						'-',
						{
							text: 'ОЧИСТИТЬ',
							handler: function (){
								Ext.MessageBox.confirm('Очистка', 'Вы уверены что хотите очистить ВСЮ таблицу?',
									function(btn){
										if(btn == 'yes') {
											this.ownerModule.app.doAjax({
												module: this.ownerModule.moduleId,
												method: 'deleteAll'
											},
											function(res) {
													this.store.load();
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
				}
			]
		});
		
		this.gridBbar = Ext.create('Ext.toolbar.Paging', {
			store: this.store,
			displayInfo: true,
			displayMsg: 'Записи {0} - {1} из {2}',
			emptyMsg: "Нет записей"
		});
		this.gridBbar.on('change', function(tb, pageData, eOpts) {
			try {
				if (this.currentPage != pageData.currentPage) this.scroll = 0;
			} catch(e) {
				this.scroll = 0;
			}
			this.currentPage = pageData.currentPage;
		}, this);
		
		this.grid = Ext.create('Ext.grid.Panel', {
			region: 'center',
			store: this.store,
			loadMask: true,
			columnLines: true,
			columns:[
				{
					header: "",
					dataIndex: 'rownum',
					width: 50,
					sortable: false,
					renderer: function(value, metaData, record) {
						if (record.get('error') == 1) metaData.style = "background-color : #ff9999 !important";
						return value;
					}
				},
				{
					header: "",
					dataIndex: '',
					width: 30,
					sortable: false,
					align: 'center',
					renderer: function(value, metaData, record) {
						if (record.get('error') == 1) metaData.style = "background-color : #ff9999 !important";
						
						if (record.get('status') == 1)
							return '<input type="button" class="x-form-field x-form-checkbox" style="background-position: 0 -13px;">';
						else
							return '';
					}
				},
				{
					header: "Номер заявки",
					dataIndex: 'tid',
					width: 100,
					sortable: false
				},
				{
					header: "Валюта выписки",
					dataIndex: 'currency',
					width: 100,
					sortable: false,
					renderer: function(value, metaData, record) {
						if (record.get('errorCurrency') == 1) metaData.style = "background-color : #ff9999 !important";
						return (value && value.length) ? value+'&nbsp;' : '&nbsp;';
					}
				},
				{
					header: "Дата ПП",
					dataIndex: 'payorderdate_str',
					width: 70,
					sortable: false
				},
				{
					header: "Списание",
					dataIndex: 'outvalue',
					width: 100,
					sortable: false
				},
				{
					header: "Поступление",
					dataIndex: 'invalue',
					width: 100,
					sortable: false
				},
				{
					header: "",
					dataIndex: 'inoutstr',
					width: 100,
					sortable: false
				},
				{
					header: "Итого",
					dataIndex: 'ordersns',
					width: 100,
					sortable: false,
					renderer: function(value, metaData, record) {
						if (record.get('errorSum') == 1) metaData.style = "background-color : #ff9999 !important";
						return value;
					}
				},

				//tms
				{
					header: "ТМС Списание",
					dataIndex: 'tms_contr_out_sum',
					width: 100,
					sortable: false
				},
				{
					header: "ТМС Валюта списания",
					dataIndex: 'tms_contr_out_currency',
					width: 100,
					sortable: false,
					renderer: function(value, metaData, record) {
						if ((record.get('inouttype') == 'OUT') && (record.get('errorCurrency') == 1)) metaData.style = "background-color : #ff9999 !important";
						return value;
					}
				},
				{
					header: "ТМС Курс списания",
					dataIndex: 'tms_contr_out_currency_rate',
					width: 100,
					sortable: false
				},
				{
					header: "ТМС Поступление",
					dataIndex: 'tms_contr_in_sum',
					width: 100,
					sortable: false
				},
				{
					header: "ТМС Валюта поступления",
					dataIndex: 'tms_contr_in_currency',
					width: 100,
					sortable: false,
					renderer: function(value, metaData, record) {
						if ((record.get('inouttype') == 'IN') && (record.get('errorCurrency') == 1)) metaData.style = "background-color : #ff9999 !important";
						return (value && value.length) ? value+'&nbsp;' : '&nbsp;';
					}
				},
				{
					header: "ТМС Курс поступления",
					dataIndex: 'tms_contr_in_currency_rate',
					width: 100,
					sortable: false
				},

				//
				{
					header: "Контрагент",
					dataIndex: 'contragent',
					width: 150,
					sortable: false,
					renderer: function(value, metaData, record) {
						if (record.get('errorContragent') == 1) metaData.style = "background-color : #ff9999 !important";
						return (value && value.length) ? value+'&nbsp;' : '&nbsp;';
					}
				},
				{
					header: "Контрагент списание ТМС",
					dataIndex: 'tms_contr_out',
					width: 150,
					sortable: false,
					renderer: function(value, metaData, record) {
						if ((record.get('inouttype') == 'OUT') && (record.get('errorContragent') == 1)) metaData.style = "background-color : #ff9999 !important";
						return (value && value.length) ? value+'&nbsp;' : '&nbsp;';
					}
				},
				{
					header: "Контрагент поступление ТМС",
					dataIndex: 'tms_contr_in',
					width: 150,
					sortable: false,
					renderer: function(value, metaData, record) {
						if ((record.get('inouttype') == 'IN') && (record.get('errorContragent') == 1)) metaData.style = "background-color : #ff9999 !important";
						return (value && value.length) ? value+'&nbsp;' : '&nbsp;';
					}
				},
				{
					header: "",
					dataIndex: 'billnum',
					width: 100,
					sortable: false
				},
				{
					header: "",
					dataIndex: 'billtid',
					width: 100,
					sortable: false
				}
			],
			viewConfig: {
				stripeRows: true
			},
			tbar: this.gridTbar,
			bbar: this.gridBbar
		});
		this.grid.on('itemdblclick', function(view, rec, item, index, eventObj, options) {
			this.ownerModule.app.doAjax({
				module: this.ownerModule.moduleId,
				method: 'toggleStatus',
				id: rec.get('id')
			},
			function(res) {
				rec.set('status', res.status)
			},
			this, this);
		}, this);

		this.grid.on('itemcontextmenu',function(view, rec, node, index, eventObj) {
			this.grid.getSelectionModel().select(index, true, true);
			var _contextMenu = Ext.create('Ext.menu.Menu', {
				items: []
			});
			_contextMenu.showAt(eventObj.getXY());
			eventObj.stopEvent();
		}, this);
		this.grid.on('select', function(sm, record, rowIndex, eOpts) {
			this.selected = record;
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
			layout: 'border',
			items: [
				this.grid//, this.infoPnl
			]
		});

		kDesktop.orderimport.main.superclass.constructor.call(this, config);
	},
	
	showMask: function(msg) {
		this.body.mask(msg + '...', 'x-mask-loading');
	},

	hideMask: function() {
		this.body.unmask();
	}
});
