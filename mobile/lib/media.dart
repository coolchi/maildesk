import 'dart:typed_data';

import 'package:audioplayers/audioplayers.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:lucide_icons/lucide_icons.dart';
import 'package:maildesk/api.dart';
import 'package:maildesk/attachments.dart';
import 'package:maildesk/models.dart';
import 'package:maildesk/theme.dart';
import 'package:pdfrx/pdfrx.dart';

class AuthedImage extends StatefulWidget {
  const AuthedImage({super.key, required this.api, required this.url, this.height = 180});

  final MailDeskApi api;
  final String url;
  final double height;

  @override
  State<AuthedImage> createState() => _AuthedImageState();
}

class _AuthedImageState extends State<AuthedImage> {
  Uint8List? bytes;
  String? error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final data = await widget.api.fetchBytes(widget.url);
      if (mounted) {
        setState(() => bytes = data);
      }
    } on ApiException catch (exception) {
      if (mounted) {
        setState(() => error = exception.message);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    if (bytes != null) {
      return ClipRRect(
        borderRadius: BorderRadius.circular(12),
        child: Image.memory(bytes!, height: widget.height, width: double.infinity, fit: BoxFit.cover),
      );
    }
    return SizedBox(
      height: 72,
      child: Center(
        child: error == null
            ? const CircularProgressIndicator(strokeWidth: 2)
            : Text(error!, style: TextStyle(color: colors.muted, fontSize: 12)),
      ),
    );
  }
}

Future<void> openImagePreview(
  BuildContext context, {
  required MailDeskApi api,
  required String url,
  String filename = 'Photo',
}) {
  return Navigator.of(context).push(
    MaterialPageRoute<void>(
      fullscreenDialog: true,
      builder: (context) => _ImagePreviewPage(api: api, url: url, filename: filename),
    ),
  );
}

class _ImagePreviewPage extends StatelessWidget {
  const _ImagePreviewPage({required this.api, required this.url, required this.filename});

  final MailDeskApi api;
  final String url;
  final String filename;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: Text(filename, maxLines: 1, overflow: TextOverflow.ellipsis),
        actions: [
          DownloadButton(api: api, url: url.split('?').first, filename: filename),
        ],
      ),
      body: _ContainedImage(api: api, url: url, muted: colors.muted),
    );
  }
}

class _ContainedImage extends StatefulWidget {
  const _ContainedImage({required this.api, required this.url, required this.muted});

  final MailDeskApi api;
  final String url;
  final Color muted;

  @override
  State<_ContainedImage> createState() => _ContainedImageState();
}

class _ContainedImageState extends State<_ContainedImage> {
  Uint8List? bytes;
  String? error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final data = await widget.api.fetchBytes(widget.url);
      if (mounted) {
        setState(() => bytes = data);
      }
    } on ApiException catch (exception) {
      if (mounted) {
        setState(() => error = exception.message);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final data = bytes;
    if (data == null) {
      return Center(
        child: error == null
            ? const CircularProgressIndicator(strokeWidth: 2, color: Colors.white)
            : Text(error!, style: TextStyle(color: widget.muted)),
      );
    }
    return InteractiveViewer(
      minScale: 1,
      maxScale: 4,
      child: Center(
        child: Image.memory(data, fit: BoxFit.contain),
      ),
    );
  }
}

class VoiceNote extends StatefulWidget {
  const VoiceNote({super.key, required this.api, required this.url, required this.mine});

  final MailDeskApi api;
  final String url;
  final bool mine;

  @override
  State<VoiceNote> createState() => _VoiceNoteState();
}

class _VoiceNoteState extends State<VoiceNote> {
  final player = AudioPlayer();
  var playing = false;
  var loading = false;

  @override
  void dispose() {
    player.dispose();
    super.dispose();
  }

  Future<void> _toggle() async {
    if (playing) {
      await player.stop();
      setState(() => playing = false);
      return;
    }
    setState(() => loading = true);
    try {
      final bytes = await widget.api.fetchBytes(widget.url);
      await player.play(BytesSource(bytes));
      setState(() {
        playing = true;
        loading = false;
      });
      player.onPlayerComplete.first.then((_) {
        if (mounted) {
          setState(() => playing = false);
        }
      });
    } on ApiException {
      if (mounted) {
        setState(() => loading = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    final color = widget.mine ? colors.onAccent : colors.text;
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        IconButton(
          onPressed: loading ? null : _toggle,
          icon: Icon(playing ? LucideIcons.square : LucideIcons.play, color: color),
        ),
        Text(playing ? 'Playing' : 'Voice note', style: TextStyle(color: color)),
      ],
    );
  }
}

Future<void> openMailPreview(BuildContext context, {required MailDeskApi api, required MailFile file}) {
  return Navigator.of(context).push(
    MaterialPageRoute<void>(
      fullscreenDialog: true,
      builder: (context) => _FilePreviewPage(api: api, file: file),
    ),
  );
}

class _FilePreviewPage extends StatelessWidget {
  const _FilePreviewPage({required this.api, required this.file});

  final MailDeskApi api;
  final MailFile file;

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    final url = file.previewUrl ?? file.url;
    return Scaffold(
      backgroundColor: colors.bg,
      appBar: AppBar(
        title: Text(file.filename, maxLines: 1, overflow: TextOverflow.ellipsis),
        actions: [
          DownloadButton(api: api, url: file.url, filename: file.filename, mimeType: file.contentType),
        ],
      ),
      body: file.isPdf
          ? RemotePdf(api: api, url: url, name: file.filename)
          : file.isVideo
              ? Padding(padding: const EdgeInsets.all(16), child: RemoteVideo(api: api, url: url))
              : InteractiveViewer(
                  child: Center(child: AuthedImage(api: api, url: url, height: 520)),
                ),
    );
  }
}

class RemotePdf extends StatefulWidget {
  const RemotePdf({super.key, required this.api, required this.url, required this.name, this.height});

  final MailDeskApi api;
  final String url;
  final String name;
  final double? height;

  @override
  State<RemotePdf> createState() => _RemotePdfState();
}

class _RemotePdfState extends State<RemotePdf> {
  Uint8List? bytes;
  var failed = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final data = await widget.api.fetchBytes(widget.url);
      if (mounted) {
        setState(() => bytes = data);
      }
    } catch (_) {
      if (mounted) {
        setState(() => failed = true);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final colors = deskColors(context);
    final viewer = bytes == null
        ? Center(
            child: failed
                ? Text('Preview unavailable', style: TextStyle(color: colors.muted, fontSize: 12))
                : const CircularProgressIndicator(strokeWidth: 2),
          )
        : PdfViewer.data(
            bytes!,
            sourceName: widget.name,
            params: PdfViewerParams(
              backgroundColor: Colors.white,
              margin: widget.height == null ? 12 : 4,
              panEnabled: widget.height == null,
              scaleEnabled: widget.height == null,
              pageDropShadow: const BoxShadow(color: Color(0x22000000), blurRadius: 8, offset: Offset(0, 2)),
            ),
          );

    if (widget.height == null) {
      return viewer;
    }

    return SizedBox(height: widget.height, width: double.infinity, child: viewer);
  }
}

class DownloadButton extends StatefulWidget {
  const DownloadButton({
    super.key,
    required this.api,
    required this.url,
    required this.filename,
    this.mimeType,
    this.color,
  });

  final MailDeskApi api;
  final String url;
  final String filename;
  final String? mimeType;
  final Color? color;

  @override
  State<DownloadButton> createState() => _DownloadButtonState();
}

class _DownloadButtonState extends State<DownloadButton> {
  var saving = false;

  Future<void> _save() async {
    if (saving || widget.url.isEmpty) {
      return;
    }
    setState(() => saving = true);
    try {
      final bytes = await widget.api.fetchBytes(widget.url);
      if (!mounted) {
        return;
      }
      final saved = await FilePicker.saveFile(
        dialogTitle: 'Download',
        fileName: widget.filename,
        bytes: bytes,
        mimeType: widget.mimeType ?? 'application/octet-stream',
      );
      if (saved != null && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Saved ${widget.filename}')));
      }
    } on ApiException catch (exception) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(exception.message)));
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Could not save this file.')));
      }
    } finally {
      if (mounted) {
        setState(() => saving = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final color = widget.color ?? deskColors(context).accent;
    if (saving) {
      return Padding(
        padding: const EdgeInsets.all(12),
        child: SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 1.6, color: color)),
      );
    }
    return IconButton(
      tooltip: 'Download',
      visualDensity: VisualDensity.compact,
      onPressed: _save,
      icon: Icon(LucideIcons.download, size: 18, color: color),
    );
  }
}
