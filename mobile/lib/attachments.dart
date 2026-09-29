import 'dart:io';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:lucide_icons/lucide_icons.dart';
import 'package:maildesk/api.dart';
import 'package:maildesk/theme.dart';
import 'package:path_provider/path_provider.dart';
import 'package:video_player/video_player.dart';

const _imageExtensions = {'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif', 'avif', 'tif', 'tiff'};
const _videoExtensions = {'mp4', 'mov', 'm4v', 'avi', 'webm', 'mkv'};

class LocalAttachment {
  const LocalAttachment({required this.path, required this.name});

  final String path;
  final String name;

  String get extension => name.contains('.') ? name.split('.').last.toLowerCase() : '';

  bool get isImage => _imageExtensions.contains(extension);

  bool get isVideo => _videoExtensions.contains(extension);
}

Future<List<LocalAttachment>> pickAttachments() async {
  final picked = await FilePicker.pickFiles();
  return [
    for (final file in picked)
      if (file.path != null) LocalAttachment(path: file.path!, name: file.name),
  ];
}

class AttachmentTray extends StatelessWidget {
  const AttachmentTray({super.key, required this.files, required this.onRemove});

  final List<LocalAttachment> files;
  final ValueChanged<LocalAttachment> onRemove;

  @override
  Widget build(BuildContext context) {
    if (files.isEmpty) {
      return const SizedBox.shrink();
    }
    return SizedBox(
      height: 84,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 16),
        itemCount: files.length,
        separatorBuilder: (context, index) => const SizedBox(width: 8),
        itemBuilder: (context, index) {
          final file = files[index];
          return _Preview(file: file, onRemove: () => onRemove(file));
        },
      ),
    );
  }
}

class _Preview extends StatelessWidget {
  const _Preview({required this.file, required this.onRemove});

  final LocalAttachment file;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    final child = file.isImage
        ? Image.file(File(file.path), width: 84, height: 84, fit: BoxFit.cover)
        : file.isVideo
            ? _LocalVideo(path: file.path)
            : Container(
                width: 140,
                height: 84,
                color: colors.bg,
                padding: const EdgeInsets.fromLTRB(10, 22, 10, 8),
                child: Text(file.name, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: colors.text, fontSize: 12)),
              );

    return Stack(
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(12),
          child: DecoratedBox(
            decoration: BoxDecoration(border: Border.all(color: colors.border)),
            child: child,
          ),
        ),
        Positioned(
          top: 4,
          right: 4,
          child: _RemoveButton(onPressed: onRemove),
        ),
      ],
    );
  }
}

class _RemoveButton extends StatelessWidget {
  const _RemoveButton({required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: const Color(0xCC09090B),
      shape: const CircleBorder(),
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: onPressed,
        child: const Padding(
          padding: EdgeInsets.all(3),
          child: Icon(LucideIcons.x, size: 14, color: Colors.white),
        ),
      ),
    );
  }
}

class _LocalVideo extends StatefulWidget {
  const _LocalVideo({required this.path});

  final String path;

  @override
  State<_LocalVideo> createState() => _LocalVideoState();
}

class _LocalVideoState extends State<_LocalVideo> {
  VideoPlayerController? controller;

  @override
  void initState() {
    super.initState();
    final player = VideoPlayerController.file(File(widget.path));
    controller = player;
    player.setVolume(0);
    player.initialize().then((_) {
      if (mounted) {
        setState(() {});
      }
    }).catchError((_) {
      if (mounted) {
        setState(() => controller = null);
      }
    });
  }

  @override
  void dispose() {
    controller?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    final player = controller;
    if (player == null || !player.value.isInitialized) {
      return Container(
        width: 84,
        height: 84,
        color: colors.bg,
        child: Icon(LucideIcons.clapperboard, color: colors.muted, size: 22),
      );
    }
    return SizedBox(
      width: 84,
      height: 84,
      child: Stack(
        fit: StackFit.expand,
        children: [
          FittedBox(
            fit: BoxFit.cover,
            child: SizedBox(
              width: player.value.size.width,
              height: player.value.size.height,
              child: VideoPlayer(player),
            ),
          ),
          const Center(child: Icon(LucideIcons.play, color: Colors.white, size: 22)),
        ],
      ),
    );
  }
}

class RemoteVideo extends StatefulWidget {
  const RemoteVideo({super.key, required this.api, required this.url});

  final MailDeskApi api;
  final String url;

  @override
  State<RemoteVideo> createState() => _RemoteVideoState();
}

class _RemoteVideoState extends State<RemoteVideo> {
  VideoPlayerController? controller;
  var failed = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final bytes = await widget.api.fetchBytes(widget.url);
      final directory = await getTemporaryDirectory();
      final file = File('${directory.path}/mail-${widget.url.hashCode}.mp4');
      await file.writeAsBytes(bytes, flush: true);
      if (!mounted) {
        return;
      }
      final player = VideoPlayerController.file(file);
      await player.setVolume(0);
      await player.initialize();
      if (mounted) {
        setState(() => controller = player);
      }
    } catch (_) {
      if (mounted) {
        setState(() => failed = true);
      }
    }
  }

  @override
  void dispose() {
    controller?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    final player = controller;
    if (player == null) {
      return SizedBox(
        height: 72,
        child: Center(
          child: failed
              ? Text('Video preview unavailable', style: TextStyle(color: colors.muted, fontSize: 12))
              : const CircularProgressIndicator(strokeWidth: 2),
        ),
      );
    }
    return ClipRRect(
      borderRadius: BorderRadius.circular(12),
      child: AspectRatio(
        aspectRatio: player.value.aspectRatio == 0 ? 16 / 9 : player.value.aspectRatio,
        child: Stack(
          alignment: Alignment.center,
          children: [
            VideoPlayer(player),
            Icon(player.value.isPlaying ? LucideIcons.pause : LucideIcons.play, color: Colors.white, size: 36),
            Positioned.fill(
              child: Material(
                color: Colors.transparent,
                child: InkWell(
                  onTap: () {
                    player.value.isPlaying ? player.pause() : player.play();
                    setState(() {});
                  },
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
