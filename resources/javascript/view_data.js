var wow_ah_data = {
	names: [],
	data_sets: {},
	current_data_set: {x: [], y: []},
	current_data_set_raw: {x: [], y: []},
	current_name: "",
	current_function: "",
	last_data_reqeust: "",
	plot_div: {},
	updatePlot: function() {
		if ($("#squash_outliers").is(":checked"))
			this.current_data_set.y = this.filters.squash_outliers(this.current_data_set_raw.y, parseInt($("#iqrs").val()), parseInt($("#window").val()), parseInt($("#overlap").val()));
		else
			this.current_data_set.y = this.current_data_set_raw.y.slice();
		switch ($("#filter").val()) {
			case "sma":
				this.current_data_set.y = this.filters.sma(this.current_data_set.y, parseInt($("#length_inp").val()));
				break;
			case "ema":
				this.current_data_set.y = this.filters.ema(this.current_data_set.y, parseInt($("#length_inp").val()), parseFloat($("#alpha_inp").val()));
				break;
			case "dema":
				this.current_data_set.y = this.filters.dema(this.current_data_set.y, parseInt($("#length_inp").val()), parseFloat($("#alpha_inp").val()));
				break;
			default:
				break;
		}
		if (this.current_parameter == "Quantity")
			var title = "Units";
		else
			var title = "Gold";
		var data = [{
			type: 'scatter',
			x: this.current_data_set.x,
			y: this.current_data_set.y
		}]
		var layout = {
			title: this.current_name + ": " + this.current_function + " of " + this.current_parameter,
			yaxis: {
				title: title,
				hoverformat: "0.2f",
				nticks: 10
			}
		}
		var options = {
			scrollZoom: true,
			showLink: false
		}
		this.plot_div.empty();
		Plotly.newPlot(this.plot_div.get(0), data, layout, options);
	},
	processData: function (data) {
		var x = data[0];
		var y = data[1];
		x = x.map(function(x) {
			var d = new Date(x * 1000);
			var year = d.getFullYear();
			var month = d.getMonth()+1;
			month = month < 10 ? '0' + month : month;
			var day = d.getDate() < 10 ? '0' + d.getDate() : d.getDate();
			var hours = d.getHours() < 10 ? '0' + d.getHours() : d.getHours();
			var minutes = d.getMinutes() < 10 ? '0' + d.getMinutes() : d.getMinutes();
			var seconds = d.getSeconds() < 10 ? '0' + d.getSeconds() : d.getSeconds();
			return year + "-" + month + "-" + day + " " + hours + ":" + minutes + ":" + seconds;
		});
		if (wow_ah_data.current_parameter != "Quantity")
			y = y.map(function(y) {	return y / 10000; });

		return {x: x, y: y};
	},
	filters: {
		sma: function(data, length) {
			data_out = Array(data.length);
			moving_avg = 0;
			for (var i = 0; i < data.length; i++) {
				if (i < length) {
					moving_avg += data[i];
					data_out[i] = data[i];
				}
				else {
					moving_avg += data[i];
					moving_avg -= data[i-length];
					data_out[i] = moving_avg / length;
				}
			}
			return data_out;
		},
		ema: function(data, length, alpha) {
			data_out = Array(data.length);
			moving_avg = 0;
			for (var i = 0; i < data.length; i++) {
				if (i < length-1) {
					moving_avg += data[i];
					data_out[i] = data[i];
				}
				else if (i == length - 1) {
					data_out[i] = (moving_avg + data[i]) / length;
				}
				else {
					data_out[i] = data[i] * alpha + (1 - alpha) * data_out[i-1];
				}
			}
			return data_out;
		},
		dema: function(data, length, alpha) {
			return this.ema(this.ema(data, length, alpha), length, alpha);
		},
		squash_outliers: function(data, IQRs, win, overlap) {
			var data_out = Array(data.length);
			var squash_to = Array(data.length);
			var i = 0;
			if (overlap > Math.ceil(win/2))
				overlap = Math.ceil(win/2);
			while (i < data.length) {
				var bounds = this.get_bounds(data.slice(i, i+win-1), IQRs);
				for (var j = 0; j < win && i+j < data.length; j++) {
					if (data[i+j] > bounds.upper && data[i+j] != 0)
						squash_to[i+j] = bounds.upper;
					else if (data[i+j] < bounds.lower && data[i+j] != 0)
						squash_to[i+j] = bounds.lower;
					else
						squash_to[i+j] = 0;
				}
				i += win - overlap;
			}
			for (var i = 0; i < data.length; i++) {
				if (squash_to[i] != 0)
					data_out[i] = squash_to[i];
				else
					data_out[i] = data[i];
			}
			return data_out;	
		},
		get_bounds: function(data, IQRs) {
			if (data.length < 5)
				return {upper:Math.max.apply(null, data), lower:Math.min.apply(null, data)};
			var data_sorted = data.slice().sort(function(a,b){return a - b});
			if (data.length % 2 == 1) {
				var median = data_sorted[(data.length-1)/2];
				var H1 = data_sorted.slice(0,(data.length-1)/2);
				var H2 = data_sorted.slice((data.length+1)/2);
			}
			else {
				var median = (data_sorted[data.length/2-1]+data_sorted[data.length/2])/2;
				var H1 = data_sorted.slice(0,data.length/2);
				var H2 = data_sorted.slice(data.length/2);
			}
			if (H1.length % 2 == 1) {
				var Q1 = H1[(H1.length-1)/2];
			}
			else {
				var Q1 = (H1[H1.length/2-1]+H1[H1.length/2])/2;
			}
			if (H2.length % 2 == 1) {
				var Q3 = H2[(H2.length-1)/2];
			}
			else {
				var Q3 = (H2[H2.length/2-1]+H2[H2.length/2])/2;
			}

			return {upper:(Q3-Q1)*IQRs+Q3, lower:Q1-(Q3-Q1)*IQRs};		
		}
	}, //filters
	getDataRequestString: function() {
		var request_string = $("#item_names").val() + $("#function").val() + $("#parameter").val();
		if ($("#condition_parameter").val() != "none") {
			request_string += $("#condition_parameter").val() + $("#comparison").val();
			switch($("#condition_parameter").val()) {
				case "unitBuyout":
				case "buyout":
				case "unitBid":
				case "bid":
					request_string += $("#gold").val() + $("#silver").val() + $("#copper").val();
					break;
				case "quantity":
					request_string += $("#quantity").val();
					break;
				case "last_time":
					request_string += $("#time_select").val();
					break;
			}
		}

		return request_string;
	}
} //wow_ah_data
$(function() {
	$.ajax({
		method: "GET",
		url: "view_data.php?action=get_items",
		dataType: "json",
		success: function(data) {
			wow_ah_data.names = data;
			$("#item_names").empty();
			$.each(data, function() {
				$("#item_names").append($("<option></option>").attr("value", this[1]).text(this[0]));
			});
		},
		fail: function(data) {
			alert("Failed to get item names.");
		}
	});
	$("#condition_parameter").change(function() {
		$(".comparison").hide();
		switch($("#condition_parameter").val()) {
			case "unitBuyout":
			case "buyout":
			case "unitBid":
			case "bid":
				$("#gold_entry").show();
				$("#comparison").show();
				break;
			case "quantity":
				$("#quantity").show();
				$("#comparison").show();
				break;
			case "last_time":
				$("#time_select").show();
				$("#comparison").show()
				break;
		}
	});
	$("#condition_parameter").trigger("change");
	$("#plot_form").submit(function(e) {
		var data_set = wow_ah_data.data_sets[wow_ah_data.getDataRequestString()];
		if (typeof data_set != "undefined") {
			wow_ah_data.current_parameter = $("#parameter option:selected").text();
			wow_ah_data.current_name = $("#item_names option:selected").text();
			wow_ah_data.current_function = $("#function option:selected").text();
			wow_ah_data.current_data_set_raw = wow_ah_data.processData(data_set);
			wow_ah_data.current_data_set.x = wow_ah_data.current_data_set_raw.x.slice();
			wow_ah_data.updatePlot();
		}
		else if ($("#plot_submit").attr("value") == "Plot!") {
			$.ajax({
				type: $("#plot_form").attr("method"),
				url: $("#plot_form").attr("action"),
				data: $("#plot_form").serialize(),
				timeout: 20000,
				beforeSend: function() {
					$("#plot_submit").attr("value","Plot..............");
					window.plotButtonInt = window.setInterval(function() {
						var label = $("#plot_submit").attr("value");
						if (label.length > 4)
							label = label.substr(0,label.length-1);
						else
							label = "Plot";
						$("#plot_submit").attr("value", label);
					}, 1000);
					wow_ah_data.last_data_request = wow_ah_data.getDataRequestString();
				},
				success: function(data_set) {
					window.clearInterval(window.plotButtonInt);
					if (data_set.error) {
						alert('Error: ' + data_set.error);
					}
					else {
						wow_ah_data.current_parameter = $("#parameter option:selected").text();
						wow_ah_data.current_name = $("#item_names option:selected").text();
						wow_ah_data.current_function = $("#function option:selected").text();
						wow_ah_data.data_sets[wow_ah_data.last_data_request] = data_set;
						wow_ah_data.current_data_set_raw = wow_ah_data.processData(data_set);
						wow_ah_data.current_data_set.x = wow_ah_data.current_data_set_raw.x.slice();
						wow_ah_data.updatePlot();
					}
					$("#plot_submit").attr("value","Plot!");
				},
				fail: function(data_set) {
					alert('Failed to submit query.');
					$("#plot_submit").attr("value","Plot!");
				}
			});
		}

		e.preventDefault();
	});
	$("#filter").change(function() {
		$(".filter").hide();
		switch($("#filter").val()) {
			case "ema":
			case "dema":
				$("#length").show();
				$("#alpha").show();
				break;
			case "sma":
				$("#length").show();
				break;
		}
	});
	$("#filter").trigger("change");
	$("#squash_outliers").change(function () {
		if ($("#squash_outliers").is(":checked"))
			$("#outliers").show();
		else
			$("#outliers").hide();
	});
	$("#apply_filter").click(function() {
		if (wow_ah_data.current_name != "")
			wow_ah_data.updatePlot();
	});
	wow_ah_data.plot_div = $("#plot");
});