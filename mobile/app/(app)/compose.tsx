import { useState } from 'react';
import {
  View,
  Text,
  TextInput,
  TouchableOpacity,
  StyleSheet,
  KeyboardAvoidingView,
  Platform,
  ScrollView,
  Alert,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Send, ChevronDown, ChevronUp, X } from 'lucide-react-native';
import { emailApi } from '../../src/api/email';
import { useAuth } from '../../src/contexts/AuthContext';
import { isPaymentRequiredError, getPaymentRequiredMessage } from '../../src/api/client';
import { PaymentBanner, Header, Button, Input, Card } from '../../src/components';
import { colors, spacing, fontSize, fontWeight, borderRadius } from '../../src/theme';
import { getShadow } from '../../src/theme/shadows';

export default function ComposeScreen() {
  const { currentWorkspace } = useAuth();
  const [to, setTo] = useState('');
  const [cc, setCc] = useState('');
  const [bcc, setBcc] = useState('');
  const [subject, setSubject] = useState('');
  const [body, setBody] = useState('');
  const [from, setFrom] = useState(currentWorkspace?.email || '');
  const [isSending, setIsSending] = useState(false);
  const [showCcBcc, setShowCcBcc] = useState(false);
  const [paymentError, setPaymentError] = useState<string | null>(null);

  const handleSend = async () => {
    if (!to.trim()) {
      Alert.alert('Error', 'Please enter a recipient');
      return;
    }
    if (!subject.trim()) {
      Alert.alert('Error', 'Please enter a subject');
      return;
    }
    if (!body.trim()) {
      Alert.alert('Error', 'Please enter a message');
      return;
    }
    if (!from.trim()) {
      Alert.alert('Error', 'Please enter a from address');
      return;
    }

    setIsSending(true);
    try {
      const html = `<p>${body.replace(/\n/g, '</p><p>')}</p>`;
      const result = await emailApi.send({
        from,
        to,
        subject,
        html,
        text: body,
        cc: cc.trim() || undefined,
        bcc: bcc.trim() || undefined,
      });

      if (result.status === 'success') {
        Alert.alert('Success', 'Email sent!');
        setTo('');
        setCc('');
        setBcc('');
        setSubject('');
        setBody('');
      } else {
        Alert.alert('Error', result.message);
      }
    } catch (error: unknown) {
      if (isPaymentRequiredError(error)) {
        setPaymentError(getPaymentRequiredMessage(error));
      } else {
        const err = error as { response?: { data?: { message?: string } } };
        Alert.alert('Error', err.response?.data?.message || 'Failed to send email');
      }
    } finally {
      setIsSending(false);
    }
  };

  const handleClear = () => {
    Alert.alert(
      'Clear Draft',
      'Are you sure you want to clear this email?',
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Clear',
          style: 'destructive',
          onPress: () => {
            setTo('');
            setCc('');
            setBcc('');
            setSubject('');
            setBody('');
          },
        },
      ]
    );
  };

  return (
    <SafeAreaView style={styles.container} edges={['top']}>
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
        <Header
          title="Compose"
          rightContent={
            <TouchableOpacity style={styles.headerButton} onPress={handleClear}>
              <X size={20} color={colors.text.muted} strokeWidth={1.75} />
            </TouchableOpacity>
          }
        />

        <ScrollView style={styles.form} keyboardShouldPersistTaps="handled">
          <Input
            label="From"
            placeholder="your@email.com"
            value={from}
            onChangeText={setFrom}
            autoCapitalize="none"
            keyboardType="email-address"
            editable={!isSending}
          />

          <Input
            label="To"
            placeholder="recipient@example.com"
            value={to}
            onChangeText={setTo}
            autoCapitalize="none"
            keyboardType="email-address"
            editable={!isSending}
          />

          <TouchableOpacity
            style={styles.ccBccToggle}
            onPress={() => setShowCcBcc(!showCcBcc)}
          >
            {showCcBcc ? (
              <ChevronUp size={16} color={colors.brand.primary} strokeWidth={2} />
            ) : (
              <ChevronDown size={16} color={colors.brand.primary} strokeWidth={2} />
            )}
            <Text style={styles.ccBccToggleText}>
              {showCcBcc ? 'Hide CC/BCC' : 'Add CC/BCC'}
            </Text>
          </TouchableOpacity>

          {showCcBcc && (
            <>
              <Input
                label="CC"
                placeholder="cc@example.com"
                value={cc}
                onChangeText={setCc}
                autoCapitalize="none"
                keyboardType="email-address"
                editable={!isSending}
              />
              <Input
                label="BCC"
                placeholder="bcc@example.com"
                value={bcc}
                onChangeText={setBcc}
                autoCapitalize="none"
                keyboardType="email-address"
                editable={!isSending}
              />
            </>
          )}

          <Input
            label="Subject"
            placeholder="Subject"
            value={subject}
            onChangeText={setSubject}
            editable={!isSending}
          />

          <View style={styles.bodyContainer}>
            <Text style={styles.label}>Message</Text>
            <TextInput
              style={styles.bodyInput}
              placeholder="Write your message..."
              placeholderTextColor={colors.text.placeholder}
              value={body}
              onChangeText={setBody}
              multiline
              numberOfLines={10}
              textAlignVertical="top"
              editable={!isSending}
            />
          </View>
        </ScrollView>

        <View style={styles.footer}>
          <Button
            title="Send Email"
            onPress={handleSend}
            loading={isSending}
            fullWidth
            size="lg"
            icon={<Send size={18} color={colors.white} strokeWidth={2} />}
          />
        </View>
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
  headerButton: {
    paddingHorizontal: spacing[3],
    paddingVertical: spacing[1.5],
  },
  form: {
    flex: 1,
    padding: spacing[4],
  },
  ccBccToggle: {
    marginBottom: spacing[4],
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing[1],
  },
  ccBccToggleText: {
    fontSize: fontSize.sm,
    color: colors.brand.primary,
    fontFamily: 'Inter_500Medium',
  },
  bodyContainer: {
    marginBottom: spacing[4],
  },
  label: {
    fontSize: fontSize.sm,
    fontWeight: fontWeight.medium,
    color: colors.text.secondary,
    marginBottom: spacing[1.5],
    fontFamily: 'Inter_500Medium',
  },
  bodyInput: {
    backgroundColor: colors.background.input,
    borderWidth: 1,
    borderColor: colors.border.primary,
    borderRadius: borderRadius.lg,
    paddingHorizontal: spacing[3],
    paddingVertical: spacing[3],
    fontSize: fontSize.base,
    color: colors.text.primary,
    minHeight: 160,
    textAlignVertical: 'top',
    fontFamily: 'Inter_400Regular',
    ...getShadow('sm'),
  },
  footer: {
    backgroundColor: colors.background.secondary,
    borderTopWidth: 1,
    borderTopColor: colors.border.primary,
    padding: spacing[4],
  },
});
