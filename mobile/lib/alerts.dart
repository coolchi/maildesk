import 'package:flutter_local_notifications/flutter_local_notifications.dart';

class Alerts {
  Alerts() : _plugin = FlutterLocalNotificationsPlugin();

  final FlutterLocalNotificationsPlugin _plugin;
  var _ready = false;

  Future<void> start() async {
    if (_ready) {
      return;
    }
    try {
      await _plugin.initialize(
        settings: const InitializationSettings(
          android: AndroidInitializationSettings('@mipmap/ic_launcher'),
          iOS: DarwinInitializationSettings(),
          macOS: DarwinInitializationSettings(),
        ),
      );
      await _plugin
          .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
          ?.requestNotificationsPermission();
      _ready = true;
    } catch (_) {
      _ready = false;
    }
  }

  Future<void> show({required int id, required String title, required String body}) async {
    if (!_ready) {
      return;
    }
    await _plugin.show(
      id: id,
      title: title,
      body: body,
      notificationDetails: const NotificationDetails(
        android: AndroidNotificationDetails(
          'maildesk',
          'MailDesk',
          channelDescription: 'New chat messages and mail',
          importance: Importance.high,
          priority: Priority.high,
        ),
        iOS: DarwinNotificationDetails(),
        macOS: DarwinNotificationDetails(),
      ),
    );
  }
}
