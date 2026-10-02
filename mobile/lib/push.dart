import 'dart:async';
import 'dart:io';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:maildesk/alerts.dart';

/// Registers for FCM and surfaces background pushes as local alerts.
/// Soft-fails when Firebase config files are missing so debug builds still run.
class DeskPush {
  DeskPush({required this.alerts});

  final Alerts alerts;
  String? token;
  var _ready = false;
  StreamSubscription<String>? _refresh;
  StreamSubscription<RemoteMessage>? _opened;
  StreamSubscription<RemoteMessage>? _foreground;

  Future<void> start({
    required Future<void> Function(String token, String platform) register,
  }) async {
    if (_ready || kIsWeb) {
      return;
    }

    try {
      await Firebase.initializeApp();
    } catch (_) {
      return;
    }

    final messaging = FirebaseMessaging.instance;
    await messaging.requestPermission(alert: true, badge: true, sound: true);
    await messaging.setForegroundNotificationPresentationOptions(alert: true, badge: true, sound: true);

    final next = await messaging.getToken();
    final platform = _platform;
    if (next != null && next.isNotEmpty && platform != null) {
      token = next;
      await register(next, platform);
    }

    _refresh?.cancel();
    _refresh = messaging.onTokenRefresh.listen((value) async {
      final currentPlatform = _platform;
      if (currentPlatform == null) {
        return;
      }
      token = value;
      await register(value, currentPlatform);
    });

    _foreground?.cancel();
    _foreground = FirebaseMessaging.onMessage.listen((message) async {
      final title = message.notification?.title ?? message.data['title'] as String? ?? 'MailDesk';
      final body = message.notification?.body ?? message.data['body'] as String? ?? '';
      if (body.isEmpty) {
        return;
      }
      await alerts.show(id: title.hashCode ^ body.hashCode, title: title, body: body);
    });

    _opened?.cancel();
    _opened = FirebaseMessaging.onMessageOpenedApp.listen((_) {});

    _ready = true;
  }

  Future<void> stop({
    required Future<void> Function(String token) unregister,
  }) async {
    final current = token;
    token = null;
    _ready = false;
    await _refresh?.cancel();
    await _foreground?.cancel();
    await _opened?.cancel();
    _refresh = null;
    _foreground = null;
    _opened = null;
    if (current == null || current.isEmpty) {
      return;
    }
    try {
      await unregister(current);
    } catch (_) {}
  }

  String? get _platform {
    if (Platform.isIOS) {
      return 'ios';
    }
    if (Platform.isAndroid) {
      return 'android';
    }
    return null;
  }
}
