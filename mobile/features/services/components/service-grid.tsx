import { useRouter } from 'expo-router';
import { StyleSheet, View } from 'react-native';
import Animated, { ZoomIn, ZoomOut } from 'react-native-reanimated';

import { Icon } from '@/components/ui/icon';
import { AppText } from '@/components/ui/text';
import { Touchable } from '@/components/ui/touchable';
import { useTheme } from '@/theme/theme';

import { groupOf, type Service, type ServiceKey } from '../catalog';
import { ServiceIcon } from './service-glyph';

/** What a tile does in edit mode: pin it, unpin it, or neither (Home is full). */
export type TileEdit = 'pin' | 'unpin' | 'full';

const COLUMNS = 4;

type ServiceTileProps = {
  service: Service;
  /** A count on the corner: invitations to answer. */
  badge?: number;
  edit?: TileEdit;
  onPress: () => void;
  onLongPress?: () => void;
};

/**
 * One service: its drawing over a short label, the GCash grid's shape. In edit
 * mode the corner carries a plus or a minus, and a tap pins or unpins it.
 */
export function ServiceTile({ service, badge, edit, onPress, onLongPress }: ServiceTileProps) {
  const { colors } = useTheme();
  const group = groupOf(service.group);
  const counted = !edit && !!badge && badge > 0;

  const label = counted ? `${service.title}, ${badge} waiting` : service.title;
  const hint =
    edit === 'pin' ? 'Adds it to Home' : edit === 'unpin' ? 'Removes it from Home' : edit === 'full' ? 'Home is full: remove one first' : undefined;

  return (
    <Touchable
      onPress={onPress}
      onLongPress={onLongPress}
      delayLongPress={350}
      disabled={edit === 'full'}
      scaleTo={0.9}
      haptic={edit ? 'selection' : 'light'}
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityHint={hint}
      accessibilityState={{ disabled: edit === 'full', selected: edit === 'unpin' }}
      style={[styles.tile, edit === 'full' && styles.dimmed]}
    >
      <View>
        <ServiceIcon glyph={service.glyph} hue={group.hue} action={service.action} />
        {counted && (
          <Animated.View entering={ZoomIn.duration(180)} style={[styles.count, { backgroundColor: colors.danger, borderColor: colors.card }]}>
            <AppText variant="caption2" weight="bold" color={colors.onDanger} style={styles.countText} numeric>
              {badge > 99 ? '99+' : badge}
            </AppText>
          </Animated.View>
        )}
        {edit && edit !== 'full' && (
          <Animated.View
            entering={ZoomIn.duration(160)}
            exiting={ZoomOut.duration(120)}
            style={[
              styles.edit,
              { backgroundColor: edit === 'unpin' ? colors.textSecondary : colors.tint, borderColor: colors.card },
            ]}
          >
            <Icon name={edit === 'unpin' ? 'minus' : 'plus'} size={10} color={colors.card} weight="bold" />
          </Animated.View>
        )}
      </View>
      <AppText variant="caption" weight="medium" center numberOfLines={2} maxFontSizeMultiplier={1.2} style={styles.label}>
        {service.label}
      </AppText>
    </Touchable>
  );
}

type ServiceGridProps = {
  services: Service[];
  badges?: Partial<Record<ServiceKey, number>>;
  /** How each tile behaves in edit mode; absent, a tap opens the service. */
  editFor?: (service: Service) => TileEdit;
  onEdit?: (service: Service) => void;
  onLongPress?: () => void;
  /** Something after the last tile, in the same grid: Home's "All services". */
  trailing?: React.ReactNode;
};

/** Services in rows of four. A short last row stays left-aligned, as a grid should. */
export function ServiceGrid({ services, badges, editFor, onEdit, onLongPress, trailing }: ServiceGridProps) {
  const router = useRouter();

  return (
    <View style={styles.grid}>
      {services.map((service) => {
        const edit = editFor?.(service);
        return (
          <View key={service.key} style={styles.cell}>
            <ServiceTile
              service={service}
              badge={badges?.[service.key]}
              edit={edit}
              onPress={() => (edit ? onEdit?.(service) : router.push(service.href))}
              onLongPress={onLongPress}
            />
          </View>
        );
      })}
      {trailing && <View style={styles.cell}>{trailing}</View>}
    </View>
  );
}

/** Home's last tile: every service, by family. */
export function AllServicesTile({ onPress }: { onPress: () => void }) {
  return (
    <Touchable
      onPress={onPress}
      scaleTo={0.9}
      haptic="light"
      accessibilityRole="button"
      accessibilityLabel="All services"
      style={styles.tile}
    >
      <ServiceIcon glyph="all" hue="#8E8E93" neutral />
      <AppText variant="caption" weight="medium" center numberOfLines={2} maxFontSizeMultiplier={1.2} style={styles.label}>
        All services
      </AppText>
    </Touchable>
  );
}

const styles = StyleSheet.create({
  grid: { flexDirection: 'row', flexWrap: 'wrap', rowGap: 16 },
  cell: { width: `${100 / COLUMNS}%`, alignItems: 'center' },
  tile: { alignItems: 'center', gap: 7, width: '100%', paddingHorizontal: 2 },
  dimmed: { opacity: 0.4 },
  label: { lineHeight: 15 },
  count: {
    position: 'absolute',
    top: -5,
    right: -7,
    minWidth: 20,
    height: 20,
    borderRadius: 10,
    borderWidth: 2,
    paddingHorizontal: 4,
    alignItems: 'center',
    justifyContent: 'center',
  },
  countText: { fontSize: 10.5, lineHeight: 13 },
  edit: {
    position: 'absolute',
    top: -6,
    right: -6,
    width: 22,
    height: 22,
    borderRadius: 11,
    borderWidth: 2,
    alignItems: 'center',
    justifyContent: 'center',
  },
});
