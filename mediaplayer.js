var client = new Client();

function Client() {
	var baseUrl = "/mediaplayer";
	this.serversUrl = baseUrl + "/servers";
	this.controlUrl = baseUrl + "/control";
	
	$.ajaxSetup({
		"error": function(jqXHR, textStatus, errorThrown) {
			$.mobile.loading('hide');
			if (console) console.log("ajax call failed: " + textStatus + " - " + jqXHR.status + " - " + jqXHR.responseText);
		}
	});
	$(window).bind('popstate', function(event) {
		var state = event.originalEvent.state;
		if (state && state["path"]) {
			var pageChange = $($.mobile.activePage).attr("id") != "browse-page";
			client.browse(state["server"], state["server"] + state["path"], true);
			if (pageChange) {
				$.mobile.changePage("index.html", { changeHash: false });
			}
		} else if (state && state["servers"]) {
			var pageChange = $($.mobile.activePage).attr("id") != "browse-page";
			client.loadServers(true);
			if (pageChange) {
				$.mobile.changePage("index.html", { changeHash: false });
			}
		}
		return true;
	});
	$(document).bind("mobileinit", function() {
		if (navigator.userAgent.indexOf("Android") != -1) {
			$.mobile.defaultPageTransition = 'none';
			$.mobile.defaultDialogTransition = 'none';
		}
	});	
}

//---------------------------------------------------------------------

Client.prototype.initBrowserPage = function() {
	var caller = this;
	$('#home').bind('click', function() {
		caller.loadServers();		
	});
	if (!this.path) {
		this.loadServers();
	} 
}

Client.prototype.initPlayerPage = function() {
	var caller = this;
	$('.control').bind('click', function() {
		var command = $(this).attr('value');
		caller.control(caller.server, null, command, "#message-player");
	});
}

//---------------------------------------------------------------------


Client.prototype.loadServers = function(skipHistory) {
	var caller = this;
	this.path = null;
	$('#title').html('Media Servers');
	$.mobile.loading('show');
	$.getJSON(this.serversUrl, function(data) {
		var elem = $('#servers');
		elem.empty();
		$.each(data["servers"], function(key, item) {
			elem.append('<li id="' + key + '"><a href="#" value="' + item["server"] + '">' + 
				item["server"] + '</a></li>');
		});
		$('#servers a').bind('click', function(event) {
			var path = $(this).attr('value');
			if (path) {
				caller.server = path;
				caller.browse(path, path);
			}
		});
		elem.listview('refresh');
		$.mobile.loading('hide');
		if (!skipHistory) {
			history.pushState({ servers: true }, "X1 Media Player", "index.html");
		}
	});
}

Client.prototype.browse = function(server, path, skipHistory) {
	var caller = this;
	$('#title').html(server);
	$.mobile.loading('show');
	$.getJSON(this.serversUrl + '/' + path, function(data) {
		var elem = $('#servers');
		var path = data["path"];
		caller.path = path;
		elem.empty();
		var parent = path;
		if (parent == "/") {
			elem.append('<li data-theme="b" data-icon="home" id="_up"><a class="folder" href="#">Up</a></li>');
		} else {
			parent = caller.getParent(server + path);
			elem.append('<li data-theme="b" data-icon="arrow-u" id="_up"><a class="folder" href="#" value="' + parent + '">Up</a></li>');
		}
		$.each(data["content"], function(key, item) {
			if (item["folder"]) {
				elem.append('<li id="' + key + '"><a class="folder" href="#" value="' + item["link"] + '">' + 
					caller.getTitle(item["folder"], false) + '</a></li>');
			} else if (item["file"]) {
				elem.append('<li data-icon="check" id="' + key + '"><a class="file" href="#" value="' + item["link"] + '">' + 
					caller.getTitle(item["file"], true) + '</a></li>');
			}
		});
		$('#servers .folder').bind('click', function(event) {
			var target = $(this).attr('value');
			if (target) {
				caller.browse(server, target);
			} else {
				caller.loadServers();
			}
		});
		$('#servers .file').bind('click', function(event) {
			var target = $(this).attr('value');
			caller.control(server, target, "play", "#message-browser");
		});
		elem.listview('refresh');
		$.mobile.loading('hide');
		if (!skipHistory) {
			history.pushState( { server: server, path: path }, "X1 Media Player", "index.html");
		}
	});
}

Client.prototype.control = function(server, target, command, id) {
	var caller = this;
	var data = { file: target, command: command };
	$.mobile.loading( 'show' );
	var url = this.controlUrl;
	if (target) {
		url = this.serversUrl + '/' + target;
	}
	$.postJSON(url, data, function(data) {
		$.mobile.loading('hide');
		caller.showMessageBox(id, data["result"], true);
	});	
}

Client.prototype.getParent = function(path) {
	path = path.replace("//","/");
	var pos = path.lastIndexOf("/");
	if (pos == path.length - 1) {
		path = path.substr(0, path.length -1);
	}
	pos = path.lastIndexOf("/");
	if (pos > 0) {
		path = path.substr(0, pos);
	}
	return path;
}

Client.prototype.getTitle = function(name, stripExtension) {	
	name = name.replace("_","/");
	if (stripExtension) {
		var pos = name.lastIndexOf(".");
		if (pos >= 0) {
			name = name.substr(0, pos);
		}
	}
	return name;
}

Client.prototype.showMessageBox = function(id, message, autoclose) {
	if (message && message != 'ok') {
		var caller = this;
		$(id).html(message);
		$(id).popup('open');
		if (autoclose) {
			window.setTimeout(function() {
				caller.closeMessageBox(id);
			}, 2000);
		}
	}
}

Client.prototype.closeMessageBox = function(id) {
	$(id).popup('close');
}

$.postJSON = function(url, data, callback) {
		return jQuery.ajax({
				'type': 'POST',
				'url': url,
				'contentType': 'application/json',
				'data': $.toJSON(data),
				'dataType': 'json',
				'success': callback
		});
};
