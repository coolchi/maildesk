import 'dart:async';

import 'package:dart_pusher_channels/dart_pusher_channels.dart';
import 'package:maildesk/models.dart';

typedef LiveHandler = void Function(String event, Map<String, dynamic> data);

class LiveConnection {
  LiveConnection({
    required this.config,
    required this.token,
    required this.onEvent,
  });

  final RealtimeConfig config;
  final String token;
  final LiveHandler onEvent;

  PusherChannelsClient? _client;
  final List<StreamSubscription<dynamic>> _subscriptions = [];
  final Set<String> _channels = {};
  final Map<String, PrivateChannel> _open = {};

  Future<void> connect(List<String> channels) async {
    if (!config.ready) {
      return;
    }
    await disconnect();
    final scheme = config.scheme == 'https' ? 'wss' : 'ws';
    final client = PusherChannelsClient.websocket(
      options: PusherChannelsOptions.fromHost(
        scheme: scheme,
        host: config.host!,
        key: config.key!,
        port: config.port,
      ),
      connectionErrorHandler: (exception, trace, refresh) {
        Future<void>.delayed(const Duration(seconds: 2), () {
          if (_client != null) {
            refresh();
          }
        });
      },
    );
    _client = client;
    for (final name in channels) {
      _channels.add(name);
    }
    _subscriptions.add(client.onConnectionEstablished.listen((_) {
      for (final name in _channels.toList()) {
        _bind(client, name);
      }
    }));
    await client.connect();
  }

  void watch(String channel) {
    _channels.add(channel);
    final client = _client;
    if (client != null) {
      _bind(client, channel);
    }
  }

  void _bind(PusherChannelsClient client, String channel) {
    final private = _open.putIfAbsent(channel, () {
      final created = client.privateChannel(
        channel,
        authorizationDelegate: EndpointAuthorizableChannelTokenAuthorizationDelegate.forPrivateChannel(
          authorizationEndpoint: Uri.parse(config.authEndpoint),
          headers: {
            'Authorization': 'Bearer $token',
            'Accept': 'application/json',
          },
        ),
      );
      _subscriptions.add(created.bindToAll().listen((event) {
        final data = event.tryGetDataAsMap();
        if (data == null || event.name.startsWith('pusher:')) {
          return;
        }
        onEvent(event.name, data);
      }));
      return created;
    });
    private.subscribe();
  }

  Future<void> disconnect() async {
    for (final subscription in _subscriptions) {
      await subscription.cancel();
    }
    _subscriptions.clear();
    _channels.clear();
    _open.clear();
    final client = _client;
    _client = null;
    if (client != null && !client.isDisposed) {
      await client.disconnect();
      client.dispose();
    }
  }
}
