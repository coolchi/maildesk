import 'package:flutter_test/flutter_test.dart';
import 'package:maildesk/chat_format.dart';

void main() {
  test('single asterisks render as bold', () {
    final runs = parseChatFormat('*Treasureland features*');

    expect(runs, hasLength(1));
    expect(runs.single.text, 'Treasureland features');
    expect(runs.single.bold, isTrue);
    expect(runs.single.italic, isFalse);
  });

  test('italic, strike, and code marks render', () {
    final runs = parseChatFormat('_note_ and ~old~ plus `id`');

    expect(runs.map((run) => run.text).join(), 'note and old plus id');
    expect(runs[0].italic, isTrue);
    expect(runs[2].strike, isTrue);
    expect(runs[4].code, isTrue);
  });

  test('marks can combine and markdown bold still works', () {
    final both = parseChatFormat('*_both_*');
    expect(both.single.text, 'both');
    expect(both.single.bold, isTrue);
    expect(both.single.italic, isTrue);

    final markdown = parseChatFormat('**strong**');
    expect(markdown.single.text, 'strong');
    expect(markdown.single.bold, isTrue);
  });

  test('a mark with a space against it stays literal', () {
    final runs = parseChatFormat('not * bold * or 2 * 3');

    expect(runs.single.text, 'not * bold * or 2 * 3');
    expect(runs.single.bold, isFalse);
  });

  test('punctuation after a mark stays outside it', () {
    final runs = parseChatFormat('*done*.');

    expect(runs.first.text, 'done');
    expect(runs.first.bold, isTrue);
    expect(runs.last.text, '.');
  });
}
