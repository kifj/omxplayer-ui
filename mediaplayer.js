var client = new Client();

function Client() {
	var baseUrl = "/omxplayer-ui";
	this.serversUrl = baseUrl + "/servers";
	this.controlUrl = baseUrl + "/control";
	this.statusUrl = baseUrl + "/status";
	this.playlistUrl = baseUrl + "/playlist";
	
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

Client.prototype.initPage = function() {
	if (this.pageChangeHandler) {
		return;
	}
	this.pageChangeHandler = true;
	var caller = this;
	$("div[data-role*='page']").live('pageshow', function(event, ui) {
		if (event.target.id == "browse-page") {
			$('#home').bind('click', function() {
				caller.loadServers();		
			});
			$('#search').bind('click', function() {
				caller.search($('#search-text').val());		
			});
			if (!caller.path) {
				caller.loadServers();
			} else {
				caller.browse(caller.server, caller.path, true);
			}
		} else if (event.target.id == "player-page") {
			$('.control').bind('click', function() {
				var command = $(this).attr('value');
				caller.control(caller.server, null, command, "#message-player");
			});
			$('#player-page').live('pageshow', function(event, ui) {
				if (!caller.statusInterval) {
					caller.getStatus(true);
					caller.statusInterval = window.setInterval("client.getStatus(true);", 5000);
				}
			});
			$('#player-page').live('pagehide', function(event, ui) {
				if (caller.statusInterval) {
					window.clearInterval(caller.statusInterval);
					caller.statusInterval = null;
				}
			});
			if (!caller.statusInterval) {
				caller.getStatus(true);
				caller.statusInterval = window.setInterval("client.getStatus(true);", 5000);
			}
		}
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
		caller.addNavigateServers(elem, data, skipHistory);
	});
}

Client.prototype.browse = function(server, path, skipHistory) {
	if (!server) return;
	var caller = this;
	$.mobile.loading('show');
	$.getJSON(this.serversUrl + '/' + path, function(data) {
		var elem = $('#servers');
		var path = data["path"];
		if (path == '/') {
			$('#title').html(server);
		} else {
			$('#title').html(caller.getFolder(path));
		}
		caller.path = path;
		elem.empty();
		var parent = path;
		caller.addNavigateUp(elem, parent, server + path);
		caller.addNavigateLinks(server, elem, data, skipHistory);
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

Client.prototype.getStatus = function(loadPlaylist) {
	var caller = this;
	$.getJSON(this.statusUrl, function(data) {
		var running = data["running"];
		caller.running = running;
		var text = "";
		if (!running) {
			$('#status').text("Player is stopped");
			$('#status').trigger("collapse");
			$('#playing').text("");
		} else {
			var title = data["title"];
			if (!title) title = caller.getTitle(data["file"], true);
			$('#status').text("Player is running");
			text = "Currently playing: " + title;
			$('#playing').text(text);
			$('#playing').append("<br/><p>")
			if (data["title"]) $('#playing').append("<b>Title:</b> " + data["title"] + "<br/>");
			if (data["playtime"]) $('#playing').append("<b>Length:</b> " + data["playtime"] + "<br/>");
			if (data["artist"]) $('#playing').append("<b>Artist:</b> " + data["artist"] + "<br/>");
			if (data["album"]) $('#playing').append("<b>Album:</b> " + data["album"] + "<br/>");
			if (data["track"]) $('#playing').append("<b>Track:</b> " + data["track"] + "<br/>");
			if (data["genre"]) $('#playing').append("<b>Genre:</b> " + data["genre"] + "<br/>");
			if (data["year"]) $('#playing').append("<b>Year:</b> " + data["year"] + "<br/>");
			$('#playing').append("</p>")
		}
	});
	if (loadPlaylist) {
		this.getPlaylist();
	}
}

Client.prototype.getPlaylist = function() {
	var caller = this;
	var elem = $('#playlist');
	elem.empty();
	$.getJSON(this.playlistUrl, function(data) {
		$.each(data["playlist"], function(key, item) {
			elem.append('<li data-icon="check" id="' + key + '"><a class="file" href="#" value="' + item["link"] + '">' + 
				caller.getTitle(item["file"], true) + '</a></li>');
		});
		elem.listview('refresh');
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

Client.prototype.getFolder = function(path) {
	path = decodeURIComponent(path);
	path = path.replace("_","/");
	var pos = path.lastIndexOf("/");
        if (pos >= 0 && pos < path.length - 1) {
		path = path.substr(pos +1);
	}
	return path;
}

Client.prototype.getTitle = function(name, stripExtension) {
	if (!name) return name;
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

Client.prototype.search = function(value, skipHistory) {
	if (!value) {
		return;
	}
	var caller = this;
	var server = this.server;
	var data = { command: 'search', value: value };
	$.mobile.loading( 'show' );
	var url = this.serversUrl + '/' + this.server;
	$.postJSON(url, data, function(data) {
		$('#search-text').val('');
		var elem = $('#servers');
		var path = data["path"];
		$('#title').html(caller.getFolder(path));
		caller.path = path;
		elem.empty();
		var parent = path;
		caller.addNavigateUp(elem, parent, server + path);
		caller.addNavigateLinks(server, elem, data, skipHistory);
	});		
}

Client.prototype.addNavigateLinks = function(server, elem, data, skipHistory) {
	var caller = this;
	var path = data["path"];
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
	$('#servers .folder').on("taphold", function(e) {
		caller.openQueueDialog(this, server);
		e.stopPropagation();
	});
	$('#servers .file').on("taphold", function(e) {
		caller.openQueueDialog(this, server);
		e.stopPropagation();
	});
	elem.listview('refresh');
	var search = data['search'];
	$('#search-box').css('display', search ? 'inline' : 'none'); 
	$.mobile.loading('hide');
	if (!skipHistory) {
		history.pushState( { server: server, path: path }, "X1 Media Player", "index.html");
	}	
}

Client.prototype.addNavigateUp = function(elem, parent, path) {
	if (parent == "/") {
		elem.append('<li data-theme="b" data-icon="home" id="_up"><a class="folder" href="#">Up</a></li>');
	} else {
		parent = this.getParent(path);
		if (parent.indexOf("/_search") == parent.length - "/_search".length) {
			elem.append('<li data-theme="b" data-icon="home" id="_up"><a class="folder" href="#">Up</a></li>');
		} else {
			elem.append('<li data-theme="b" data-icon="arrow-u" id="_up"><a class="folder" href="#" value="' + parent + '">Up</a></li>');
		}
	}	
}

Client.prototype.addNavigateServers = function(elem, data, skipHistory) {
	var caller = this;
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
	$('#search-box').css('display', 'none'); 
	$.mobile.loading('hide');
	if (!skipHistory) {
		history.pushState({ servers: true }, "X1 Media Player", "index.html");
	}	
}

Client.prototype.closeMessageBox = function(id) {
	$(id).popup('close');
}

Client.prototype.openQueueDialog = function(item, server) {
	var caller = this;
	var target = $(item).attr('value');
	var value = $(item).text();
	var buttons = {};
	buttons['Play'] = {
		click: function () { 
			caller.control(server, target, "play", "#message-browser");
			this.close();
		},
		corners: false
	};
	buttons['Add to playlist'] = {
		click: function () { 
			caller.control(server, target, "add", "#message-browser");
			this.close();
		},
		icon: "arrow-r",
		theme: "c",
		corners: false
	};
	buttons['Remove from playlist'] = {
		click: function () { 
			caller.control(server, target, "remove", "#message-browser");
			this.close();
		},  
		icon: "arrow-l",
		theme: "c",
		corners: false
	};
	buttons['Cancel'] = {
		click: function () { 
			this.close();
		},  
		icon: "delete",
		theme: "c",
		corners: false
	};
	$('<div>').simpledialog2({
	    mode: 'button',
		buttonPrompt: value,
	    headerText: "Please choose",
	    headerClose: true,
	    buttons : buttons
	    }
	);
	$('.ui-simpledialog-controls a').css('margin', '0');
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
