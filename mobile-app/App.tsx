import React, { useMemo, useState } from 'react';
import { SafeAreaView, StatusBar, View } from 'react-native';
import { Theme, theme } from './src/theme';
import { ListingsScreen } from './src/screens/ListingsScreen';
import { DetailScreen } from './src/screens/DetailScreen';

export default function App() {
  const t: Theme = useMemo(() => theme, []);
  const [selectedId, setSelectedId] = useState<number | null>(null);

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: t.colors.bg }}>
      <StatusBar barStyle="dark-content" />
      <View style={{ flex: 1 }}>
        {selectedId === null ? (
          <ListingsScreen theme={t} onOpenDetail={(id) => setSelectedId(id)} />
        ) : (
          <DetailScreen
            theme={t}
            id={selectedId}
            onBack={() => setSelectedId(null)}
          />
        )}
      </View>
    </SafeAreaView>
  );
}

