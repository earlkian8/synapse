import { Children, createContext, Fragment, isValidElement, useContext, type ReactNode } from 'react';
import { StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';

import { Icon, type IconName } from '@/components/ui/icon';
import { AppText } from '@/components/ui/text';
import { Touchable } from '@/components/ui/touchable';
import { useTheme } from '@/theme/theme';

/** Where a row's text starts when it carries an icon well: gutter + well + gap. */
const WELL = 30;
const INSET = 16;
const GAP = 12;

/** The group's fill, so a row's pressed highlight fades back to the right colour. */
const SurfaceContext = createContext<string | null>(null);

type ListSectionProps = {
  /** A short header above the group, in the grouped-table style. */
  header?: string;
  /** A note under the group, for what the rows above mean. */
  footer?: string;
  /** Rows carry icon wells: the hairlines between them start after the well. */
  withIcons?: boolean;
  /** Rows carry a wider leading element (a logo, a date): the hairlines start after it. */
  leadingWidth?: number;
  /** On a sheet: a step up from the sheet's own surface, as iOS draws it after dark. */
  raised?: boolean;
  children: ReactNode;
  style?: StyleProp<ViewStyle>;
};

/**
 * An inset grouped list: rows on one card, hairlines between them that stop short
 * of the leading edge, with an optional header and footer outside the card. This is
 * the iOS Settings layout, and the right shape for anything that is a set of labelled
 * values or a set of places to go.
 */
export function ListSection({ header, footer, withIcons, leadingWidth, raised, children, style }: ListSectionProps) {
  const { colors, radius, squircle, scheme } = useTheme();
  const rows = Children.toArray(children).filter(isValidElement);
  const surface = raised ? colors.elevated : colors.card;
  const lead = leadingWidth ?? (withIcons ? WELL : 0);

  return (
    <View style={style}>
      {header && (
        <AppText variant="footnote" tone="secondary" style={styles.header}>
          {header.toUpperCase()}
        </AppText>
      )}
      {/* The shadow sits outside the clip: a view that clips its corners clips its shadow too. */}
      <View style={[{ borderRadius: radius.lg }, scheme === 'light' && { boxShadow: '0 1px 2px rgba(0, 0, 0, 0.04)' }]}>
        <View style={{ backgroundColor: surface, borderRadius: radius.lg, overflow: 'hidden', ...squircle }}>
          <SurfaceContext.Provider value={surface}>
            {rows.map((row, index) => (
              <Fragment key={row.key ?? index}>
                {index > 0 && (
                  <View
                    style={{
                      height: StyleSheet.hairlineWidth,
                      backgroundColor: colors.separator,
                      marginLeft: lead > 0 ? INSET + lead + GAP : INSET,
                    }}
                  />
                )}
                {row}
              </Fragment>
            ))}
          </SurfaceContext.Provider>
        </View>
      </View>
      {footer && (
        <AppText variant="footnote" tone="secondary" style={styles.footer}>
          {footer}
        </AppText>
      )}
    </View>
  );
}

type ListRowProps = {
  title: string;
  subtitle?: string;
  /** A value on the trailing side, as in Settings > General > About. */
  value?: string;
  icon?: IconName;
  /** The well's fill. The glyph on it is white. */
  iconColor?: string;
  /** Anything else on the leading side, in place of an icon: a logo, a date block. */
  leading?: ReactNode;
  /** Anything else on the trailing side: a switch, a pill, a spinner. */
  accessory?: ReactNode;
  onPress?: () => void;
  /** A chevron, saying the row leads somewhere. On by default for a pressable row. */
  chevron?: boolean;
  /** A checkmark, for the chosen row in a pick-one list. */
  selected?: boolean;
  destructive?: boolean;
  /** Let a long value wrap instead of truncating (an address). */
  wrap?: boolean;
  accessibilityLabel?: string;
};

export function ListRow({
  title,
  subtitle,
  value,
  icon,
  iconColor,
  leading,
  accessory,
  onPress,
  chevron,
  selected,
  destructive,
  wrap,
  accessibilityLabel,
}: ListRowProps) {
  const { colors } = useTheme();
  const surface = useContext(SurfaceContext) ?? colors.card;
  const showChevron = chevron ?? (!!onPress && selected === undefined);

  const body = (
    <View style={styles.row}>
      {icon && (
        <View style={[styles.well, { backgroundColor: iconColor ?? colors.tint }]}>
          <Icon name={icon} size={16} color="#FFFFFF" weight="semibold" />
        </View>
      )}
      {leading}
      <View style={styles.titles}>
        <AppText variant="body" tone={destructive ? 'danger' : 'primary'} numberOfLines={1}>
          {title}
        </AppText>
        {subtitle && (
          <AppText variant="footnote" tone="secondary" numberOfLines={2}>
            {subtitle}
          </AppText>
        )}
      </View>
      {value !== undefined && (
        <AppText
          variant="body"
          tone="secondary"
          numberOfLines={wrap ? 3 : 1}
          style={[styles.value, wrap && styles.valueWrap]}
          selectable={!onPress}
        >
          {value}
        </AppText>
      )}
      {accessory}
      {selected && <Icon name="check" size={17} color={colors.tintText} weight="semibold" />}
      {showChevron && <Icon name="chevronRight" size={13} color={colors.textTertiary} weight="semibold" />}
    </View>
  );

  if (!onPress) {
    return body;
  }

  return (
    <Touchable
      feedback="highlight"
      restColor={surface}
      onPress={onPress}
      haptic="selection"
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? [title, value].filter(Boolean).join(', ')}
      accessibilityState={selected !== undefined ? { selected } : undefined}
    >
      {body}
    </Touchable>
  );
}

const styles = StyleSheet.create({
  header: { marginLeft: INSET, marginBottom: 7, letterSpacing: 0.2 },
  footer: { marginHorizontal: INSET, marginTop: 7 },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: GAP,
    minHeight: 50,
    paddingHorizontal: INSET,
    paddingVertical: 11,
  },
  well: {
    width: WELL,
    height: WELL,
    borderRadius: 8,
    borderCurve: 'continuous',
    alignItems: 'center',
    justifyContent: 'center',
  },
  titles: { flex: 1, gap: 2 },
  value: { flexShrink: 1, maxWidth: '58%', textAlign: 'right' },
  valueWrap: { maxWidth: '62%' },
});
