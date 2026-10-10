import { useEffect, useState } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';

import { Avatar } from '@/components/ui/avatar';
import { Input } from '@/components/ui/input';
import { ListRow, ListSection } from '@/components/ui/list';
import { AppText } from '@/components/ui/text';
import { useTheme } from '@/theme/theme';
import type { Colleague } from '@/types/api';

import { recognitionApi } from './api';

type Props = {
  selected: Colleague | null;
  onSelect: (colleague: Colleague | null) => void;
  error?: string;
};

type Found = { query: string; people: Colleague[] | null };

/**
 * Find a colleague by name. The chosen one shows as a row with Change; until
 * then, a search field and up to eight matches.
 */
export function ColleagueSearch({ selected, onSelect, error }: Props) {
  const { colors } = useTheme();
  const [query, setQuery] = useState('');
  const [found, setFound] = useState<Found>({ query: '\u0000', people: null });

  useEffect(() => {
    if (selected) return;

    let cancelled = false;
    // A short pause so each keystroke doesn't ask.
    const timer = setTimeout(() => {
      recognitionApi
        .colleagues(query)
        .then((result) => !cancelled && setFound({ query, people: result.data.slice(0, 8) }))
        .catch(() => !cancelled && setFound({ query, people: [] }));
    }, 250);

    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [query, selected]);

  if (selected) {
    return (
      <ListSection header="Who" leadingWidth={36}>
        <ListRow
          leading={<Avatar uri={selected.photo} initials={selected.initials} size={34} />}
          title={selected.name}
          subtitle={selected.position ?? undefined}
          accessory={
            <AppText variant="body" tone="tint" onPress={() => onSelect(null)} accessibilityRole="button">
              Change
            </AppText>
          }
        />
      </ListSection>
    );
  }

  const loading = found.query !== query;

  return (
    <View style={styles.gap}>
      <Input
        label="Who"
        icon="person"
        value={query}
        onChangeText={setQuery}
        placeholder="Search by name"
        autoCorrect={false}
        autoCapitalize="words"
        returnKeyType="search"
        error={error}
      />
      {loading && found.people === null ? (
        <ActivityIndicator color={colors.textTertiary} />
      ) : (found.people ?? []).length === 0 ? (
        <AppText variant="footnote" tone="secondary" style={styles.none}>
          No one by that name.
        </AppText>
      ) : (
        <ListSection leadingWidth={36}>
          {(found.people ?? []).map((person) => (
            <ListRow
              key={person.id}
              leading={<Avatar uri={person.photo} initials={person.initials} size={34} />}
              title={person.name}
              subtitle={person.position ?? undefined}
              onPress={() => onSelect(person)}
            />
          ))}
        </ListSection>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  gap: { gap: 10 },
  none: { paddingHorizontal: 4 },
});
