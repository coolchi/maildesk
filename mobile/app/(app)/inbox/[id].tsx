import { useState, useEffect, useCallback } from 'react';
import {
  View,
  Text,
  ScrollView,
  TouchableOpacity,
  TextInput,
  StyleSheet,
  ActivityIndicator,
  Alert,
  KeyboardAvoidingView,
  Platform,
  useWindowDimensions,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useLocalSearchParams, router } from 'expo-router';
import { WebView } from 'react-native-webview';
import { Archive, Inbox, Trash2, Reply, Paperclip, Send, ArrowUp, ArrowDown } from 'lucide-react-native';
import { inboxApi } from '../../../src/api/inbox';
import { isPaymentRequiredError, getPaymentRequiredMessage } from '../../../src/api/client';
import { PaymentBanner, Button, Card } from '../../../src/components';
import { colors, spacing, fontSize, fontWeight, borderRadius } from '../../../src/theme';
import { getShadow } from '../../../src/theme/shadows';
import type { Thread, Message } from '../../../src/api/types';

export default function ThreadScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { width: screenWidth } = useWindowDimensions();
  const [thread, setThread] = useState<Thread | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [replyText, setReplyText] = useState('');
  const [isSending, setIsSending] = useState(false);
  const [showReply, setShowReply] = useState(false);
  const [paymentError, setPaymentError] = useState<string | null>(null);

  const loadThread = useCallback(async () => {
    if (!id) return;
    
    try {
      const response = await inboxApi.getThread(parseInt(id, 10));
      setThread(response.thread);
    } catch (error) {
      console.error('Failed to load thread:', error);
      Alert.alert('Error', 'Failed to load conversation');
      router.back();
    }
  }, [id]);

  useEffect(() => {
    setIsLoading(true);
    loadThread().finally(() => setIsLoading(false));
  }, [loadThread]);

  const handleArchive = async () => {
    if (!thread) return;
    
    try {
      await inboxApi.toggleArchive(thread.id);
      Alert.alert('Success', thread.is_archived ? 'Moved to inbox' : 'Archived');
      router.back();
    } catch (error) {
      if (isPaymentRequiredError(error)) {
        setPaymentError(getPaymentRequiredMessage(error));
      } else {
        Alert.alert('Error', 'Failed to update');
      }
    }
  };

  const handleTrash = async () => {
    if (!thread) return;
    
    Alert.alert(
      'Move to Trash',
      'Are you sure you want to move this conversation to trash?',
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Trash',
          style: 'destructive',
          onPress: async () => {
            try {
              await inboxApi.toggleTrash(thread.id);
              router.back();
            } catch (error) {
              if (isPaymentRequiredError(error)) {
                setPaymentError(getPaymentRequiredMessage(error));
              } else {
                Alert.alert('Error', 'Failed to move to trash');
              }
            }
          },
        },
      ]
    );
  };

  const handleSendReply = async () => {
    if (!thread || !replyText.trim()) return;
    
    setIsSending(true);
    try {
      const html = `<p>${replyText.replace(/\n/g, '</p><p>')}</p>`;
      const result = await inboxApi.reply(thread.id, { html });
      
      if (result.status === 'success') {
        setReplyText('');
        setShowReply(false);
        await loadThread();
        Alert.alert('Success', 'Reply sent');
      } else {
        Alert.alert('Error', result.message);
      }
    } catch (error) {
      if (isPaymentRequiredError(error)) {
        setPaymentError(getPaymentRequiredMessage(error));
      } else {
        Alert.alert('Error', 'Failed to send reply');
      }
    } finally {
      setIsSending(false);
    }
  };

  const renderMessage = (message: Message, index: number) => {
    const isOutbound = message.direction === 'outbound';
    const htmlContent = message.html || `<p>${message.text || ''}</p>`;
    
    const injectedHtml = `
      <!DOCTYPE html>
      <html>
        <head>
          <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
          <style>
            body {
              font-family: -apple-system, BlinkMacSystemFont, 'Inter', sans-serif;
              font-size: 15px;
              line-height: 1.5;
              color: ${colors.text.primary};
              margin: 0;
              padding: 12px;
              word-wrap: break-word;
              overflow-wrap: break-word;
            }
            img { max-width: 100%; height: auto; }
            a { color: ${colors.brand.primary}; }
            blockquote {
              border-left: 3px solid ${colors.border.primary};
              margin: 8px 0;
              padding-left: 12px;
              color: ${colors.text.muted};
            }
          </style>
        </head>
        <body>${htmlContent}</body>
      </html>
    `;

    return (
      <View 
        key={message.id} 
        style={[styles.messageContainer, isOutbound && styles.messageOutbound]}
      >
        <View style={styles.messageHeader}>
          <View style={styles.messageFrom}>
            <Text style={styles.messageFromName}>
              {message.from_name || message.from}
            </Text>
            <View style={styles.directionRow}>
              {isOutbound ? (
                <ArrowUp size={12} color={colors.text.muted} strokeWidth={2} />
              ) : (
                <ArrowDown size={12} color={colors.text.muted} strokeWidth={2} />
              )}
              <Text style={styles.messageDirection}>
                {isOutbound ? 'Sent' : 'Received'}
              </Text>
            </View>
          </View>
          <Text style={styles.messageTime}>{message.sent}</Text>
        </View>
        
        {message.to && (
          <Text style={styles.messageTo}>To: {message.to}</Text>
        )}
        
        <View style={[styles.messageBody, { width: screenWidth - 56 }]}>
          <WebView
            originWhitelist={['*']}
            source={{ html: injectedHtml }}
            style={styles.webView}
            scrollEnabled={false}
            javaScriptEnabled={false}
            onShouldStartLoadWithRequest={() => false}
            injectedJavaScript={`
              document.body.style.height = document.body.scrollHeight + 'px';
              window.ReactNativeWebView.postMessage(document.body.scrollHeight);
            `}
          />
        </View>

        {message.attachments.length > 0 && (
          <View style={styles.attachments}>
            <Paperclip size={14} color={colors.text.muted} strokeWidth={1.5} />
            <Text style={styles.attachmentsLabel}>{message.attachments.length} attachment(s)</Text>
          </View>
        )}
      </View>
    );
  };

  if (isLoading) {
    return (
      <SafeAreaView style={styles.loadingContainer}>
        <ActivityIndicator size="large" color={colors.brand.primary} />
      </SafeAreaView>
    );
  }

  if (!thread) {
    return (
      <SafeAreaView style={styles.errorContainer}>
        <Text style={styles.errorText}>Thread not found</Text>
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.container} edges={['bottom']}>
      <KeyboardAvoidingView 
        style={styles.flex}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        {paymentError && (
          <PaymentBanner
            message={paymentError}
            onDismiss={() => setPaymentError(null)}
          />
        )}
        <View style={styles.subjectBar}>
          <Text style={styles.subject} numberOfLines={2}>
            {thread.subject || '(no subject)'}
          </Text>
          <View style={styles.actions}>
            <TouchableOpacity style={styles.actionButton} onPress={handleArchive}>
              {thread.is_archived ? (
                <Inbox size={20} color={colors.text.secondary} strokeWidth={1.75} />
              ) : (
                <Archive size={20} color={colors.text.secondary} strokeWidth={1.75} />
              )}
            </TouchableOpacity>
            <TouchableOpacity style={styles.actionButton} onPress={handleTrash}>
              <Trash2 size={20} color={colors.status.error.text} strokeWidth={1.75} />
            </TouchableOpacity>
          </View>
        </View>

        <ScrollView style={styles.messages} contentContainerStyle={styles.messagesContent}>
          {thread.messages.map((message, index) => renderMessage(message, index))}
        </ScrollView>

        {showReply ? (
          <View style={styles.replyBox}>
            <TextInput
              style={styles.replyInput}
              placeholder="Write your reply..."
              placeholderTextColor={colors.text.placeholder}
              value={replyText}
              onChangeText={setReplyText}
              multiline
              numberOfLines={4}
              editable={!isSending}
            />
            <View style={styles.replyActions}>
              <Button
                title="Cancel"
                onPress={() => {
                  setShowReply(false);
                  setReplyText('');
                }}
                variant="ghost"
                size="sm"
                disabled={isSending}
              />
              <Button
                title="Send Reply"
                onPress={handleSendReply}
                size="sm"
                disabled={!replyText.trim()}
                loading={isSending}
              />
            </View>
          </View>
        ) : (
          <View style={styles.replyButtonContainer}>
            <Button
              title="Reply"
              onPress={() => setShowReply(true)}
              fullWidth
              icon={<Reply size={18} color={colors.white} strokeWidth={2} />}
            />
          </View>
        )}
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: colors.background.primary,
  },
  flex: {
    flex: 1,
  },
  loadingContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    backgroundColor: colors.background.primary,
  },
  errorContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    backgroundColor: colors.background.primary,
  },
  errorText: {
    fontSize: fontSize.base,
    color: colors.text.muted,
    fontFamily: 'Inter_400Regular',
  },
  subjectBar: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: colors.background.secondary,
    paddingHorizontal: spacing[4],
    paddingVertical: spacing[3],
    borderBottomWidth: 1,
    borderBottomColor: colors.border.primary,
  },
  subject: {
    flex: 1,
    fontSize: fontSize.base,
    fontWeight: fontWeight.semibold,
    color: colors.text.primary,
    marginRight: spacing[3],
    fontFamily: 'Inter_600SemiBold',
  },
  actions: {
    flexDirection: 'row',
  },
  actionButton: {
    padding: spacing[2],
    marginLeft: spacing[1],
  },
  messages: {
    flex: 1,
  },
  messagesContent: {
    padding: spacing[3],
  },
  messageContainer: {
    backgroundColor: colors.background.secondary,
    borderRadius: borderRadius.xl,
    padding: spacing[3.5],
    marginBottom: spacing[3],
    ...getShadow('sm'),
  },
  messageOutbound: {
    backgroundColor: colors.brand.primaryLight,
  },
  messageHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
    marginBottom: spacing[2],
  },
  messageFrom: {
    flex: 1,
  },
  messageFromName: {
    fontSize: fontSize.sm,
    fontWeight: fontWeight.semibold,
    color: colors.text.primary,
    fontFamily: 'Inter_600SemiBold',
  },
  directionRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing[1],
    marginTop: spacing[0.5],
  },
  messageDirection: {
    fontSize: fontSize.xs,
    color: colors.text.muted,
    fontFamily: 'Inter_400Regular',
  },
  messageTime: {
    fontSize: fontSize.xs,
    color: colors.text.placeholder,
    fontFamily: 'Inter_400Regular',
  },
  messageTo: {
    fontSize: fontSize.xs,
    color: colors.text.muted,
    marginBottom: spacing[2],
    fontFamily: 'Inter_400Regular',
  },
  messageBody: {
    minHeight: 60,
    overflow: 'hidden',
  },
  webView: {
    backgroundColor: 'transparent',
    minHeight: 80,
  },
  attachments: {
    marginTop: spacing[3],
    paddingTop: spacing[3],
    borderTopWidth: 1,
    borderTopColor: colors.border.primary,
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing[1.5],
  },
  attachmentsLabel: {
    fontSize: fontSize.sm,
    color: colors.text.muted,
    fontFamily: 'Inter_400Regular',
  },
  replyButtonContainer: {
    padding: spacing[3],
  },
  replyBox: {
    backgroundColor: colors.background.secondary,
    borderTopWidth: 1,
    borderTopColor: colors.border.primary,
    padding: spacing[3],
  },
  replyInput: {
    backgroundColor: colors.background.primary,
    borderWidth: 1,
    borderColor: colors.border.primary,
    borderRadius: borderRadius.md,
    padding: spacing[3],
    fontSize: fontSize.base,
    color: colors.text.primary,
    minHeight: 100,
    textAlignVertical: 'top',
    fontFamily: 'Inter_400Regular',
  },
  replyActions: {
    flexDirection: 'row',
    justifyContent: 'flex-end',
    marginTop: spacing[3],
    gap: spacing[2],
  },
});
