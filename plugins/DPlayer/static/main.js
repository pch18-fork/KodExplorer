kodReady.push(function(){
	Events.bind('explorer.kodApp.before',function(appList){
		appList.push({
			name:"DPlayer",
			title:LNG['Plugin.default.DPlayer'],
			ext:"{{config.fileExt}}",
			sort:"{{config.fileSort}}",
			icon:'{{pluginHost}}static/images/icon.png',
			callback:function(path,ext,name){
				var vedio = {
					url:core.path2url(path),
					name:name,path:path,ext:ext
				};
				var appStatic = "{{pluginHost}}static/";
				requireAsync(appStatic+'page.js',function(play){
					play(appStatic,vedio);
				});
			}
		});
	});
	window.DplayerSubtitle = parseInt("{{config.subtitle}}");
});
