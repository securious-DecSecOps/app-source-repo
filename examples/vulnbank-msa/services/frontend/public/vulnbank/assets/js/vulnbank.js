$.fn.bootstrapSwitch.defaults.onColor = 'success';
$.fn.bootstrapSwitch.defaults.offColor = 'danger';

// === MSA token-based auth bridge ===
// Phase 4에서 백엔드가 HMAC Bearer token으로 전환됨.
// 로그인 응답의 token을 localStorage에 보관하고 모든 후속 api.php 호출에
// Authorization: Bearer 헤더를 자동 첨부한다.
var VB_TOKEN_KEY = "vb_token";
function vb_get_token() { return localStorage.getItem(VB_TOKEN_KEY) || ""; }
function vb_set_token(t) { if (t) localStorage.setItem(VB_TOKEN_KEY, t); }
function vb_clear_token() { localStorage.removeItem(VB_TOKEN_KEY); }

$.ajaxSetup({
    beforeSend: function (xhr) {
        var t = vb_get_token();
        if (t) xhr.setRequestHeader("Authorization", "Bearer " + t);
    },
    statusCode: {
        401: function () {
            vb_clear_token();
            if (window.location.pathname.indexOf("login.php") === -1) {
                window.location.replace("login.php");
            }
        }
    }
});

var vb_current_user = null;
var vb_settings_loading = false;

function vb_b64url_decode(value) {
    var normalized = value.replace(/-/g, "+").replace(/_/g, "/");
    while (normalized.length % 4) normalized += "=";
    return atob(normalized);
}

function vb_token_claims() {
    var token = vb_get_token();
    if (!token || token.indexOf(".") === -1) return {};
    try {
        return JSON.parse(vb_b64url_decode(token.split(".")[0]));
    } catch (e) {
        return {};
    }
}

function vb_escape(value) {
    return $("<div/>").text(value == null ? "" : value).html();
}

function vb_api_type() {
    return $('meta[name=api]').attr("type") || "none";
}

function vb_post(data, success, error) {
    $.ajax({
        url: "api.php",
        type: "POST",
        data: data,
        contentType: "application/x-www-form-urlencoded",
        dataType: "json",
        async: true,
        success: success,
        error: error || function (xhr) {
            var payload = {};
            try { payload = $.parseJSON(xhr.responseText); } catch (e) {}
            notify("danger", payload.icon || "pe-7s-attention", payload.message || "Request failed");
        }
    });
}

function vb_load_current_user(callback) {
    if (vb_current_user) {
        if (callback) callback(vb_current_user);
        return;
    }
    var claims = vb_token_claims();
    if (!claims.id) {
        if (callback) callback(null);
        return;
    }
    vb_post({"type": "user", "action": "lookup_by_id", "id": claims.id}, function (data) {
        vb_current_user = data.user || null;
        vb_apply_identity(vb_current_user || claims);
        if (callback) callback(vb_current_user);
    });
}

function vb_apply_identity(user) {
    if (!user) return;
    $('meta[name=currentUser]').attr("type", user.login || "none");
    $("#balance").text("Balance: " + (user.amount == null ? "--" : user.amount));
    $("#menu-welcome").html("Welcome " + vb_escape(((user.firstname || "") + " " + (user.lastname || "")).replace(/^\s+|\s+$/g, "")) + ' <b class="caret"></b>');
    $(".admin-only").toggle((user.role || "") === "admin");
    $("#transactions-sender").val(user.account || "");
}

function vb_transaction_icon(approved) {
    if (parseInt(approved, 10) === 1) return '<i style="font-size:2em;color:green" class="pe-7s-check"></i>';
    if (parseInt(approved, 10) === 2) return '<i style="font-size:2em;color:red;" class="pe-7s-close-circle"></i>';
    if (parseInt(approved, 10) === 3) return '<i style="font-size:2em;color:#f49242;" class="pe-7s-back"></i>';
    return '<i style="font-size:2em;color:#f49242;" class="pe-7s-clock"></i>';
}

function vb_transaction_row(tx) {
    var outgoing = tx.direction === "outgoing";
    var amount = tx.display_amount != null ? tx.display_amount : (outgoing ? "-" + tx.amount : tx.amount);
    var cls = outgoing ? "text-danger" : "text-success";
    return "<tr>" +
        "<td>" + vb_transaction_icon(tx.approved) + "</td>" +
        '<td><p class="' + cls + '">' + vb_escape(amount) + "$</p></td>" +
        "<td>" + vb_escape(tx.name || "") + "</td>" +
        "<td>" + vb_escape(tx.account || "") + "</td>" +
        "<td>" + vb_escape(tx.creditcard || "") + "</td>" +
        "<td>" + vb_escape(tx.timestamp || "") + "</td>" +
        "<td>" + vb_escape(tx.comment || "") + "</td>" +
        "</tr>";
}

function vb_load_transactions(action, tbodySelector) {
    vb_load_current_user(function (user) {
        if (!user || !user.account) return;
        vb_post({"type": "transaction", "action": action, "account_number": user.account}, function (data) {
            var rows = "";
            $.each(data.transactions || [], function (idx, tx) {
                rows += vb_transaction_row(tx);
            });
            $(tbodySelector).html(rows);
            if (tbodySelector === "#history-history-body") {
                if ($.fn.DataTable.isDataTable("#history-history")) {
                    $("#history-history").DataTable().destroy();
                }
                $("#history-history").DataTable({"order":[[5, "desc"]], "oSearch": {"sSearch": location.hash.substr(1)}});
            }
        });
    });
}

function vb_settings_label(name) {
    var labels = {
        "nexmo_api_key": "Nexmo SMS API key",
        "nexmo_api_secret": "Nexmo SMS API secret",
        "sms_api": "SMS API",
        "upload_path": "Avatars upload path",
        "vb_api": "VulnBank API",
        "vb_otp": "OTP"
    };
    return labels[name] || name;
}

function vb_setting_row(row) {
    var name = row.param_name;
    var value = row.param_value == null ? "" : row.param_value;
    var label = vb_settings_label(name);
    if (row.param_type === "checkbox") {
        return '<tr><td style="text-align:center;vertical-align:middle">' + vb_escape(label) + '</td>' +
            '<td style="text-align:center;vertical-align:middle" id="settings-' + vb_escape(name) + '">' +
            '<input class="form-control" type="checkbox" ' + (String(value) === "1" ? "checked" : "") + "></td></tr>";
    }
    if (row.param_type === "options") {
        var options = name === "vb_api" ? ["none", "rest", "xml"] : ["nexmo"];
        var html = '<tr><td style="text-align:center;vertical-align:middle">' + vb_escape(label) + '</td>' +
            '<td style="text-align:center;vertical-align:middle"><select class="form-control" id="settings-' + vb_escape(name) + '">';
        $.each(options, function (idx, option) {
            html += '<option name="' + option.toUpperCase() + ' API" data="' + option + '"' +
                (String(value) === option ? ' selected="selected"' : "") + ">" + option.toUpperCase() + " API</option>";
        });
        return html + "</select></td></tr>";
    }
    return '<tr><td style="text-align:center;vertical-align:middle">' + vb_escape(label) + '</td>' +
        '<td style="text-align:center;vertical-align:middle"><input id="settings-' + vb_escape(name) + '" class="form-control" type="text" value="' + vb_escape(value) + '"></td></tr>';
}

function vb_load_settings() {
    vb_settings_loading = true;
    vb_post({"type": "settings", "action": "list"}, function (data) {
        var rows = "";
        $.each(data.settings || [], function (idx, row) {
            rows += vb_setting_row(row);
        });
        $("#settings-settings-body").html(rows);
        $("#settings-settings-body :checkbox").bootstrapSwitch();
        vb_settings_loading = false;
    }, function (xhr) {
        vb_settings_loading = false;
        var payload = {};
        try { payload = $.parseJSON(xhr.responseText); } catch (e) {}
        notify("danger", payload.icon || "pe-7s-attention", payload.message || "Settings load failed");
    });
}

function vb_user_row(user) {
    var id = user.id;
    return '<tr>' +
        '<td style="text-align:center;vertical-align:middle">' + vb_escape(id) + '</td>' +
        '<td style="text-align:center;vertical-align:middle">' + vb_escape(user.login) + '</td>' +
        '<td style="text-align:center;vertical-align:middle">' + vb_escape(user.firstname) + '</td>' +
        '<td style="text-align:center;vertical-align:middle">' + vb_escape(user.lastname) + '</td>' +
        '<td style="text-align:center;vertical-align:middle">' + vb_escape(user.email) + '</td>' +
        '<td style="text-align:center;vertical-align:middle">' + vb_escape(user.account) + '</td>' +
        '<td style="text-align:center;vertical-align:middle">' + vb_escape(user.creditcard) + '</td>' +
        '<td style="text-align:center;vertical-align:middle">' + vb_escape(user.birthdate) + '</td>' +
        '<td style="text-align:center;vertical-align:middle"><input id="users-amount' + vb_escape(id) + '" lineid="users-' + vb_escape(id) + '" class="form-control" type="number" value="' + vb_escape(user.amount) + '"></td>' +
        '<td style="text-align:center;vertical-align:middle"><select class="form-control" lineid="users-' + vb_escape(id) + '" id="users-roleselect' + vb_escape(id) + '">' +
        '<option' + (user.role === "admin" ? ' selected="selected"' : "") + '>admin</option>' +
        '<option' + (user.role === "user" ? ' selected="selected"' : "") + '>user</option>' +
        '</select></td>' +
        '<td style="text-align:center;vertical-align:middle" lineid="users-' + vb_escape(id) + '" id="users-otp' + vb_escape(id) + '"><input class="form-control" type="checkbox" ' + (parseInt(user.otp, 10) === 1 ? "checked" : "") + '></td>' +
        '<td style="vertical-align:middle"><button id="users-deleteuser' + vb_escape(id) + '" lineid="users-' + vb_escape(id) + '" class="btn btn-danger btn-fill btn-simple btn-xs" rel="tooltip" type="button" data-original-title="Delete"><i class="fa fa-times"></i></button></td>' +
        '</tr>';
}

function vb_load_users() {
    vb_post({"type": "user", "action": "summary"}, function (data) {
        var items = data.balances || [];
        var users = [];
        var remaining = items.length;
        if (!remaining) {
            $("#users-users-body").html("");
            return;
        }
        $.each(items, function (idx, item) {
            vb_post({"type": "user", "action": "lookup_by_id", "id": item.id}, function (detail) {
                users.push(detail.user || item);
                remaining -= 1;
                if (remaining === 0) {
                    users.sort(function (a, b) { return parseInt(a.id, 10) - parseInt(b.id, 10); });
                    var rows = "";
                    $.each(users, function (i, user) { rows += vb_user_row(user); });
                    $("#users-users-body").html(rows);
                    $("#users-users-body :checkbox").bootstrapSwitch();
                }
            });
        });
    });
}

function vb_apply_userinfo(user) {
    if (!user) return;
    $("#userinfo-avatar").attr("src", user.avatar || "../assets/img/default-avatar.png");
    $("#userinfo-displayname").html(vb_escape((user.firstname || "") + " " + (user.lastname || "")) + "<br /><small id=\"userinfo-displaylogin\">" + vb_escape(user.login || "") + "</small>");
    $("#userinfo-description").html(vb_escape(user.about || "").replace(/\n/g, "<br/>"));
    $("#userinfo-account").val(user.account || "");
    $("#userinfo-creditcard").val(user.creditcard || "");
    $("#userinfo-login").val(user.login || "");
    $("#userinfo-phone").val(user.phone || "");
    $("#userinfo-firstname").val(user.firstname || "");
    $("#userinfo-lastname").val(user.lastname || "");
    $("#userinfo-email").val(user.email || "");
    $("#userinfo-birthdate").val(user.birthdate || "");
    $("#userinfo-about").val(user.about || "");
}

var baseurl = window.location.pathname.split("/");
var currentPage = baseurl[baseurl.length - 1];

addEventListener("load", function() {
    setTimeout(hideURLbar, 0);
}, false);

$(document).ready(function () {
    if ($("meta[name='csrf-token-name']").length) { $("#sidebar").attr("data-color", "red") }
    $('#login-horizontalTab').easyResponsiveTabs({ type: 'default', width: 'auto', fit: true });
    jQuery.event.props.push('dataTransfer');
    $(":checkbox").bootstrapSwitch();
    $('.selectpicker').selectpicker();
    $('#userinfo-birthdate').datetimepicker({ format: "YYYY-MM-DD"  });
    $("#createuser-creditcard, #login-register-creditcard").val(get_cc().match(/.{1,4}/g).join('-'));
    $("#createuser-account, #login-register-account").val(get_acc());
    $('#createuser-birthdate, #login-register-birthdate, #createuser-birthdate').datetimepicker({ format: "DD-MM-YYYY" });
    $("#createuser-firstname, #createuser-lastname, #createuser-username, #login-reset-username, #login-login-username, #login-register-username, #login-register-firstname, #login-register-lastname, #userinfo-firstname, #userinfo-lastname").on("change", function(event) { validate($(this), /^[a-zA-Z0-9\.-_']*$/); });
    $("#createuser-account").on("change", function(event) { validate($(this), /^[A-Z]{2}[0-9]{20}$/); });
    $("#createuser-phone, #login-login-code, #login-register-phone, #login-reset-code, #transactions-code, #userinfo-phone").on("change", function(event) { validate($(this), /^[0-9]*$/); });
    $("#createuser-creditcard, #userinfo-birthdate").on("change", function(event) { validate($(this), /^[0-9-]*$/); });
    $("#createuser-email, #login-register-email, #userinfo-email").on("change",function(event) {
        validate($(this), /^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/);
    });
    $("#transactions-amount").on("change",function(event) { validate($(this),/^[0-9-\.]*$/); });
    $("#history-searchinfo").html("Searching... " + decodeURIComponent(location.hash.substr(1)));
    $("#history-searchfield").on("change", function (event) { location.hash = location.hash + $(this).val(); });
    $("#users-createuser").on("click", function(event) { window.location = "createuser.php"; });
    $("#userinfo-avatar").on("click", function() { $("#userinfo-upload").click(); });
    $("#userinfo-newpassword, #userinfo-confirmpass").on("change", function (event) {
        var newpassword = $("#userinfo-newpassword");
        var confirmpass = $("#userinfo-confirmpass");
        if ((newpassword) && (confirmpass)) {
            var is_equal = (newpassword.val() == confirmpass.val());
            if (is_equal) {
                validate(newpassword, /.*/);
                validate(confirmpass, /.*/);
            }
        }
    });
    $("#language").on("change", function(event) {
        var api = $('meta[name=api]').attr("type");
        var data = {"type": "settings",
                    "action": "changelocale",
                    "language": $(this).val()}
        send_ajax(data, api, {"reload":1});
    });

    if (!vb_get_token() && currentPage !== "login.php" && currentPage !== "forgot.php") {
        window.location.replace("login.php?r=" + encodeURIComponent(currentPage));
        return;
    }
    if (currentPage !== "login.php" && currentPage !== "forgot.php") {
        vb_load_current_user();
    }

    switch (currentPage) {
        case "createuser.php":
            $("#createuser-password, #createuser-confirmpassword").on("change",function(event) {
                var password = $("#createuser-password").val();
                var confirmpassword = $("#createuser-confirmpassword").val();
                if ((password) && (confirmpassword)) {
                    var is_valid = password == confirmpassword;
                    if(is_valid) {
                        $("#createuser-password").css({"border-color": "green"}, {"box-shadow": "0 0 10px green"})
                        $("#createuser-confirmpassword").css({"border-color": "green"}, {"box-shadow": "0 0 10px green"})
                    } else {
                        $("#createuser-password").css({"border-color": "red"}, {"box-shadow": "0 0 10px red"})
                        $("#createuser-confirmpassword").css({"border-color": "red"}, {"box-shadow": "0 0 10px red"})
                    }
                }
            });

            $("#createuser-createuser").on("submit",function(event) {
                event.preventDefault();
                var api = $('meta[name=api]').attr("type");
                var username = $("#createuser-username").val();
                var password = $("#createuser-password").val();
                var account = $("#createuser-account").val();
                var creditcard = $("#createuser-creditcard").val();
                var creditcard = $("#createuser-creditcard").val();
                var firstname = $("#createuser-firstname").val();
                var lastname = $("#createuser-lastname").val();
                var email = $("#createuser-email").val();
                var phone = $("#createuser-phone").val();
                var birthdate = $("#createuser-birthdate").val();
                var data = {"type": "user",
                            "action": "create",
                            "username": username,
                            "password": password,
                            "firstname": firstname,
                            "lastname": lastname,
                            "account": account,
                            "creditcard": creditcard,
                            "email": email,
                            "phone": phone,
                            "birthdate": birthdate};
                send_ajax(data, api, {"notify":1})
            });
            break;
        case "history.php":
            vb_load_transactions("history", "#history-history-body");

            $("#history-removefailed").on("click",function(event) {
                event.preventDefault();
                var api = $('meta[name=api]').attr("type");
                var data = {"type": "transaction",
                            "action": "clear"};
                send_ajax(data, api, {"notify":1, "reload":1});
            });

            $("#history-canceloperations").on("click",function(event) {
                event.preventDefault();
                var api = $('meta[name=api]').attr("type");
                var data = {"type": "transaction",
                            "action": "cancel"};
                send_ajax(data, api, {"notify":1,"reload":1});
            });
            break;
        case "login.php":
            $("#login-reset-password,#login-reset-confirmpassword").on("change",function(event) {
                var password = $("#login-reset-password").val();
                var confirmpassword = $("#login-reset-confirmpassword").val();
                if ((password) && (confirmpassword)) {
                    valid = password == confirmpassword;
                    if (valid) {
                        $("#login-reset-password").css({"border-color": "green"},{"box-shadow": "0 0 10px green"})
                        $("#login-reset-confirmpassword").css({"border-color": "green"},{"box-shadow": "0 0 10px green"})
                    } else {
                        $("#login-reset-password").css({"border-color": "red"},{"box-shadow": "0 0 10px red"})
                        $("#login-reset-confirmpassword").css({"border-color": "red"},{"box-shadow": "0 0 10px red"})
                    }
                }
            });

            $("#login-register-password,#login-register-confirmpassword").on("change",function(event) {
                var password = $("#login-register-password").val();
                var confirmpassword = $("#login-register-confirmpassword").val();
                if ((password) && (confirmpassword)) {
                    valid = password == confirmpassword;
                    if (valid) {
                        $("#login-register-password").css({"border-color": "green"},{"box-shadow": "0 0 10px green"})
                        $("#login-register-confirmpassword").css({"border-color": "green"},{"box-shadow": "0 0 10px green"})
                    } else {
                        $("#login-register-password").css({"border-color": "red"},{"box-shadow": "0 0 10px red"})
                        $("#login-register-confirmpassword").css({"border-color": "red"},{"box-shadow": "0 0 10px red"})
                    }
                }
            });

            $("#login-loginform").on("submit",function(event) {
                event.preventDefault();
                var api = "none";
                var codefield = $("#login-login-code");
                var username = $("#login-login-username").val();
                var password = $("#login-login-password").val();
                // var api = $('meta[name=api]').attr("type");
                var data = {"type": "user",
                            "action": "login",
                            "username": username,
                            "password": password,
                            "code": codefield.val()};
                var url = "?" + decodeURIComponent(window.location.search.substring(1));
                $.ajax({url: "api.php",
                        type: "POST",
                        data: data,
                        contentType: "application/x-www-form-urlencoded",
                        dataType: "json",
                        async: true,
                        success: function (data) {
                            if (data.token) vb_set_token(data.token);
                            if (data.message.indexOf("Code") != -1) {
                                codefield.show();
                                $("#login-login-p").show();
                                notify("success", data.icon, data.message);
                            } else {
                                notify("success", data.icon, data.message);
                                window.location.replace(url);
                            }
                        },
                        error: function (data) {
                            var data = $.parseJSON(data.responseText)
                            notify("danger", data.icon, data.message);
                        }
                });
            });

            $("#login-reset-codebutton").on("click",function(event) {
                event.preventDefault();
                var username = $("#login-reset-username").val();
                if (!username) { notify("danger", "pe-7s-key", "Username not set"); }
                else {
                    var api = $('meta[name=api]').attr("type");
                    var data = {"type": "code",
                                "action": "sms",
                                "username": username};
                    send_ajax(data, api, {"notify":1});
                }
            });

            $("#login-reset-submit").on("click",function(event) {
                event.preventDefault();
                var username = $('#login-reset-username');
                var password = $('#login-reset-password');
                var code = $('#login-reset-code');
                var api = $('meta[name=api]').attr("type");
                valid = false;
                valid = valid || validate(username, /^[a-zA-Z0-9\.-_]*$/);
                valid = valid || validate(code, /^[0-9]{3}$/);
                var data = {"type": "user",
                            "action": "forgotpass",
                            "username": username.val(),
                            "password": password.val(),
                            "code": code.val()};
                send_ajax(data, api, {"notify":1});
            });

            $("#login-register-submit").on("click",function(event) {
                event.preventDefault();
                var username = $('#login-register-username');
                var password = $('#login-register-password');
                var firstname = $('#login-register-firstname');
                var lastname = $('#login-register-lastname');
                var birthdate = $('#login-register-birthdate');
                var email = $('#login-register-email');
                var phone = $('#login-register-phone');
                var creditcard = $('#login-register-creditcard');
                var account = $('#login-register-account');
                var api = $('meta[name=api]').attr("type");
                valid = false;
                valid = valid || validate(username, /^[a-zA-Z0-9\.-_]*$/);
                valid = valid || validate(firstname, /^[a-zA-Z0-9\.-_]*$/);
                valid = valid || validate(lastname, /^[a-zA-Z0-9\.-_]*$/);
                valid = valid || validate(password, /^[a-zA-Z0-9\.-_]*$/);
                valid = valid || validate(email, /^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/);
                valid = valid || validate(phone, /^[0-9]*$/);
                var data = {"type": "user",
                            "action": "create",
                            "username": username.val(),
                            "password": password.val(),
                            "firstname": firstname.val(),
                            "lastname": lastname.val(),
                            "birthdate": birthdate.val(),
                            "email": email.val(),
                            "phone": phone.val(),
                            "account": account.val(),
                            "creditcard": creditcard.val()};
                send_ajax(data, api, {"notify":1});
            });
            break;
        case "portal.php":
            vb_load_current_user(function(user) {
                if (!user) return;
                vb_post({"type":"transaction", "action":"graph", "account": user.account}, function (data) {
                    generate_graph(data);
                });
                vb_load_transactions("recent", "#portal-recent-transactions");
            });
            break;
        case "settings.php":
            vb_load_settings();

            $("#settings-dbreset").on("click", function(event) {
                var api = $('meta[name=api]').attr("type");
                var data = { "type": "settings", "action": "resetdb" }
                send_ajax(data, api, {"notify": 1});
            });

            $("#settings-settings-body").on("change switchChange.bootstrapSwitch", ".form-control", function(event, state) {
                if (vb_settings_loading) return;
                var vb_api = $("[name='" + $("#settings-vb_api").val() +"']").attr("data")
                var sms_api = $("[name='" + $("#settings-sms_api").val() +"']").attr("data")
                $('meta[name=api]').attr("type", vb_api);
                var api = $('meta[name=api]').attr("type");
                var data = {"type": "settings",
                            "action": "update",
                            "vb_api": vb_api,
                            "sms_api": sms_api,
                            "vb_otp": Boolean($("#settings-vb_otp .bootstrap-switch-on").length),
                            "nexmo_api_key": $("#settings-nexmo_api_key").val(),
                            "nexmo_api_secret": $("#settings-nexmo_api_secret").val(),
                            "upload_path": $("#settings-upload_path").val()
                }
                send_ajax(data, api, {"notify": 1});
            });
            break;
        case "status.php":
            setInterval(send_ajax_status, 1000);
            break;
        case "transactions.php":
            vb_load_current_user(function(user) {
                if (user) $("#transactions-sender").val(user.account || "");
            });

            $("#transactions-firstname, #transactions-lastname, #transactions-recipient, #transactions-creditcard").on("change",function(event) {
                var api = $('meta[name=api]').attr("type");
                var firstname = $("#transactions-firstname");
                var lastname = $("#transactions-lastname");
                var recipient = $("#transactions-recipient");
                var creditcard = $("#transactions-creditcard");
                valid = false;
                //valid = valid || validate(firstname, /^[a-zA-Z0-9]*$/);
                valid = valid || validate(lastname, /^[a-zA-Z0-9]*$/);
                valid = valid || validate(recipient, /^[A-Z]{2}[0-9]{20}$/);
                valid = valid || validate(creditcard, /^[0-9-]*$/);
                if (valid) {
                    var data = {"type": "user",
                                "action": "check",
                                "firstname": firstname.val(),
                                "lastname": lastname.val(),
                                "creditcard": creditcard.val(),
                                "recipient": recipient.val()};
                    send_ajax(data, api, {"highlight":["firstname", "lastname", "creditcard", "recipient"]});
                }
            });

            $("#transactions-code-send").on("click",function(event) {
                event.preventDefault();
                var api = $('meta[name=api]').attr("type");
                var id = $("#transactions-id").val();
                var code = $("#transactions-code").val();
                var data = {"type": "transaction",
                            "action": "verify",
                            "id": id,
                            "code": code};
                send_ajax(data, api, {"notify":1});
                $("#transactions-modal").modal("toggle");
            });

            $("#transactions-transaction").on("submit",function(event) {
                event.preventDefault();
                var api = $('meta[name=api]').attr("type");
                var sender = $("#transactions-sender").val();
                var recipient = $("#transactions-recipient").val();
                var creditcard = $("#transactions-creditcard").val();
                var amount = $("#transactions-amount").val();
                var comment = $("#transactions-comment").val();
                var data = {"type": "transaction",
                            "action": "send",
                            "sender": sender,
                            "recipient": recipient,
                            "creditcard": creditcard,
                            "amount": amount,
                            "comment": comment};
                send_ajax(data, api, {"notify":1});
            });
            break;
        case "userinfo.php":
            $("body").on("dragover", function(event) { event.preventDefault; return false; });
            $("body").on("dragleave", function(event) { event.preventDefault(); return false; });

            $("#userinfo-upload").on("change", function() {
                var file = $("#userinfo-upload")[0].files[0];
                var data = new FormData();
                var claims = vb_token_claims();
                data.append("type", "file");
                data.append("action", "upload_avatar");
                if (claims.id) data.append("id", claims.id);
                data.append("upload_avatar", file);
                upld(data);
            });

            $("body").on("drop", function(event) {
                event.preventDefault();
                var files = event.dataTransfer.files;
                var data = new FormData();
                var claims = vb_token_claims();
                data.append("type", "file");
                data.append("action", "upload_avatar");
                if (claims.id) data.append("id", claims.id);
                data.append("upload_avatar", files[0]);
                upld(data);
            });

            vb_load_current_user(vb_apply_userinfo);

            $("#userinfo-userupdate").on("click", function(event) {
                event.preventDefault();
                var api = $('meta[name=api]').attr("type");
                var firstname = $("#userinfo-firstname");
                var lastname = $("#userinfo-lastname");
                var phone = $("#userinfo-phone");
                var email = $("#userinfo-email");
                var birthdate = $("#userinfo-birthdate");
                var about = $("#userinfo-about");
                valid = true;
                valid = valid && validate(firstname, /^[0-9a-zA-Z'_\.]*$/);
                valid = valid && validate(lastname, /^[0-9a-zA-Z'_\.]*$/);
                valid = valid && validate(birthdate, /^[0-9-]*$/);
                valid = valid && validate(phone, /^[0-9\-\(\)]*$/);
                valid = valid && validate(email, /^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/);
                if (valid) {
                    var data = {"type": "user",
                                "action": "infoupdate",
                                "firstname": firstname.val(),
                                "lastname": lastname.val(),
                                "phone": phone.val(),
                                "email": email.val(),
                                "birthdate": birthdate.val(),
                                "about": about.val()}
                    send_ajax(data, api, {"notify": 1});
                    $("#userinfo-description").html(about.val().replace(/\n/g, "<br/>"));
                }
            });

            $("#userinfo-changepass").on("submit", function (event){
                event.preventDefault();
                var oldpassword = $("#userinfo-oldpassword")
                var newpassword = $("#userinfo-newpassword")
                var confirmpass = $("#userinfo-confirmpass")
                if (confirmpass.val() != newpassword.val()) {
                    validate(newpassword, /$a/); 
                    validate(confirmpass, /$a/); 
                } else {
                    var api = $('meta[name=api]').attr("type");
                    var data = {"type": "user",
                                "action": "changepass",
                                "oldpassword": oldpassword.val(),
                                "newpassword": newpassword.val()}
                    send_ajax(data, api, {"notify":1})
                }
            });
            break;
        case "users.php":
            vb_load_users();

            $("#users-users-body").on("click", ".btn-danger", function(event) {
                var api = $('meta[name=api]').attr("type");
                var id = $(this).attr("lineid").replace("users-", "");
                var data = {"type": "user",
                        "action": "delete",
                        "id": id};
                send_ajax(data, api, {"notify":1, "reload":1});
            });

            $("#users-users-body").on("change switchChange.bootstrapSwitch", ".form-control", function(event, state) {
                var api = $('meta[name=api]').attr("type");
                try { var lineid=$(this).attr("lineid").replace("users-", ""); }
                catch(err) { lineid = $(this).closest("td").attr("lineid").replace("users-", ""); }
                var amountid="#users-amount"+lineid;
                var roleid="#users-roleselect"+lineid;
                var data = {"type": "user",
                            "action": "update",
                            "amount": $(amountid).val(),
                            "id": lineid,
                            "otp": Boolean($("#users-otp"+lineid+" .bootstrap-switch-on").length),
                            "role": $(roleid).val()};
                send_ajax(data, api, {"notify": 1});
            });
            break;
    }
});
