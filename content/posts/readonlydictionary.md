---
title:	"ReadOnlyDictionary"
date:	2007-11-22
author: Steven van Deursen
tags:   [.NET General, C#]
draft:	false
---

### This article describes an implementation of a `ReadOnlyDictionary<TKey, TValue>` that's missing from the .NET framework.

#### UPDATE 2012-06-05: .NET 4.5 will (finally finally!!) contain a `ReadOnlyDictionary<TKey, TValue>`, which will make this post (that has long be my top most googled article) finally redundant. If you're still developing under .NET 4.0 or below, please read on.

#### UPDATE 2013-04-11: Software license notice: I previously released this under the MIT license, but decided to change this. The source code presented in this post is released as 'public domain'. This means that you can do whatever you want with it, no need to tell anyone about it, but don't blame me if shit hits the fan.

I always wondered why Microsoft didn't add a ReadOnlyDictionary class in the System.Collections.Generic or System.Collections.ObjectModel namespace. I'm not alone. This feature request has been posted at least twice on the Microsoft Connect feedback website and many people decided to write their own implementation.

None of the implementations I found on the internet appealed to me. The two biggest problems I had with them where that they weren't actually read-only and didn't implement interface members explicitly. The latter complicates the use of the dictionary unnecessarily while using it through IntelliSense.

While I could try to fix one of those implementations, I decided to write my own and looked closely at the Dictionary<TKey, TValue> and `ReadOnlyCollection<T>` implementations that already were in the framework. In the code below you'll see that the implementation is rather straight forward. I've implemented the correct interfaces, with most methods explicitly and I wrapped a `Dictionary<TKey, TValue>` internally. The internal dictionary is a copy of the provided constructor argument. This last point is rather important, because the other implementations I saw didn't make such a copy, allowing you to change the read-only dictionary after creation by using a reference to the original dictionary. Last but not least is the implementation of a ReadOnlyDictionaryDebugView class that helps you display the dictionary during debugging.

#### UPDATE 2008-03-02:

Looking once more at .NET’s `ReadOnlyCollection<T>` implementation, I noticed that the implementation doesn’t make a copy of the supplied collection; it simply wraps it! After giving it some thought, it made a lot of sense to me. By wrapping the original collection, the read-only collection copies the original collection’s behavior. Copying the behavior is important, because otherwise you would end up creating a read-only version of each and every type implementing ICollection or IDictionary for which you’d like to have a read-only wrapper, simply because each type possibly behaves differently.

For the `ReadOnlyDictionary<TKey, TValue>` it is even clearer. Whether the dictionary contains a key or not, is determined by how equality is defined for type TKey. I already noticed this behavioral problem in my original implementation, which is why I included a constructor with an `IEqualityComparer<TKey>` argument. But this simply isn’t enough, because supplying an `IEqualityComparer<TKey>` is possibly not suitable for every dictionary implementation. Remember that we expect an `IDictionary<TKey, TValue>` to be given by the user, so there actually isn’t that much we know about the implementation of the supplied object.

My conclusion is that copying the supplied dictionary is actually a design flaw. The only reasonable thing the ReadOnlyDictionary can do is to wrap the given dictionary. This however leads to an implementation that is not truly read-only, but so is the framework’s ReadOnlyCollection implementation. Therefore we shift the responsibility for this to the user of our implementation.

#### UPDATE 2010-05-20:
I updated the formatting and XML comments in a way that StyleCop likes it.

#### UPDATE 2011-03-22:
Jacek noted correctly in the comments that the ReadOnlyDictionary made the assumption that the wrapped dictionary always implemented the old non-generic ICollection and IDictionary interfaces. While most types in the framework that implement `IDictionary<TKey, TValue>` also implement ICollection and IDictionary, not all types do, and more importantly, types simply don't have to. To fix this, I had to remove the IDictionary interface from the ReadOnlyDictionary. It still implements ICollection though.

Here is my (corrected) `ReadOnlyDictionary<TKey, TValue>` implementation.

{{< highlight csharp >}}
using System;
using System.Collections;
using System.Collections.Generic;
using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Threading;

 /// <summary>
 /// Provides the base class for a generic read-only dictionary.
 /// </summary>
 /// <typeparam name="TKey">
 /// The type of keys in the dictionary.
 /// </typeparam>
 /// <typeparam name="TValue">
 /// The type of values in the dictionary.
 /// </typeparam>
 /// <remarks>
 /// <para>
 /// An instance of the <b>ReadOnlyDictionary</b> generic class is
 /// always read-only. A dictionary that is read-only is simply a
 /// dictionary with a wrapper that prevents modifying the
 /// dictionary; therefore, if changes are made to the underlying
 /// dictionary, the read-only dictionary reflects those changes. 
 /// See <see cref="Dictionary{TKey,TValue}"/> for a modifiable version of 
 /// this class.
 /// </para>
 /// <para>
 /// <b>Notes to Implementers</b> This base class is provided to 
 /// make it easier for implementers to create a generic read-only
 /// custom dictionary. Implementers are encouraged to extend this
 /// base class instead of creating their own. 
 /// </para>
 /// </remarks>
[Serializable]
[DebuggerDisplay("Count = {Count}")]
[ComVisible(false)]
[DebuggerTypeProxy(typeof(ReadOnlyDictionaryDebugView<,>))]
public class ReadOnlyDictionary<TKey, TValue> : IDictionary<TKey, TValue>,
    ICollection
{
    private readonly IDictionary<TKey, TValue> source;
    private object syncRoot;

    /// <summary>
    /// Initializes a new instance of the
    /// <see cref="T:ReadOnlyDictionary`2" /> class that wraps
    /// the supplied <paramref name="dictionaryToWrap"/>.
    /// </summary>
    /// <param name="dictionaryToWrap">The <see cref="T:IDictionary`2" />
    /// that will be wrapped.</param>
    /// <exception cref="T:System.ArgumentNullException">
    /// Thrown when the dictionary is null.
    /// </exception>
    public ReadOnlyDictionary(IDictionary<TKey, TValue> dictionaryToWrap)
    {
        if (dictionaryToWrap == null)
        {
            throw new ArgumentNullException("dictionaryToWrap");
        }

        this.source = dictionaryToWrap;
    }

    /// <summary>
    /// Gets the number of key/value pairs contained in the
    /// <see cref="T:ReadOnlyDictionary`2"></see>.
    /// </summary>
    /// <value>The number of key/value pairs.</value>
    /// <returns>The number of key/value pairs contained in the
    /// <see cref="T:ReadOnlyDictionary`2"></see>.</returns>
    public int Count
    {
        get { return this.source.Count; }
    }

    /// <summary>Gets a collection containing the keys in the
    /// <see cref="T:ReadOnlyDictionary{TKey,TValue}"></see>.</summary>
    /// <value>A <see cref="Dictionary{TKey,TValue}.KeyCollection"/> 
    /// containing the keys.</value>
    /// <returns>A
    /// <see cref="Dictionary{TKey,TValue}.KeyCollection"/>
    /// containing the keys in the
    /// <see cref="Dictionary{TKey,TValue}"></see>.
    /// </returns>
    public ICollection<TKey> Keys
    {
        get { return this.source.Keys; }
    }

    /// <summary>
    /// Gets a collection containing the values of the
    /// <see cref="T:ReadOnlyDictionary`2"/>.
    /// </summary>
    /// <value>The collection of values.</value>
    public ICollection<TValue> Values
    {
        get { return this.source.Values; }
    }

    /// <summary>Gets a value indicating whether the dictionary is read-only.
    /// This value will always be true.</summary>
    bool ICollection<KeyValuePair<TKey, TValue>>.IsReadOnly
    {
        get { return true; }
    }

    /// <summary>
    /// Gets a value indicating whether access to the dictionary
    /// is synchronized (thread safe).
    /// </summary>
    bool ICollection.IsSynchronized
    {
        get { return false; }
    }

    /// <summary>
    /// Gets an object that can be used to synchronize access to dictionary.
    /// </summary>
    object ICollection.SyncRoot
    {
        get
        {
            if (this.syncRoot == null)
            {
                ICollection collection = this.source as ICollection;

                if (collection != null)
                {
                    this.syncRoot = collection.SyncRoot;
                }
                else
                {
                    Interlocked.CompareExchange(ref this.syncRoot, new object(), null);
                }
            }

            return this.syncRoot;
        }
    }

    /// <summary>
    /// Gets or sets the value associated with the specified key.
    /// </summary>
    /// <returns>
    /// The value associated with the specified key. If the specified key
    /// is not found, a get operation throws a 
    /// <see cref="T:System.Collections.Generic.KeyNotFoundException" />,
    /// and a set operation creates a new element with the specified key.
    /// </returns>
    /// <param name="key">The key of the value to get or set.</param>
    /// <exception cref="T:System.ArgumentNullException">
    /// Thrown when the key is null.
    /// </exception>
    /// <exception cref="T:System.Collections.Generic.KeyNotFoundException">
    /// The property is retrieved and key does not exist in the collection.
    /// </exception>
    public TValue this[TKey key]
    {
        get { return this.source[key]; }
        set { ThrowNotSupportedException(); }
    }

    /// <summary>This method is not supported by the 
    /// <see cref="T:ReadOnlyDictionary`2"/>.</summary>
    /// <param name="key">
    /// The object to use as the key of the element to add.</param>
    /// <param name="value">
    /// The object to use as the value of the element to add.</param>
    void IDictionary<TKey, TValue>.Add(TKey key, TValue value)
    {
        ThrowNotSupportedException();
    }

    /// <summary>Determines whether the <see cref="T:ReadOnlyDictionary`2" />
    /// contains the specified key.</summary>
    /// <returns>
    /// True if the <see cref="T:ReadOnlyDictionary`2" /> contains
    /// an element with the specified key; otherwise, false.
    /// </returns>
    /// <param name="key">The key to locate in the
    /// <see cref="T:ReadOnlyDictionary`2"></see>.</param>
    /// <exception cref="T:System.ArgumentNullException">
    /// Thrown when the key is null.
    /// </exception>
    public bool ContainsKey(TKey key)
    {
        return this.source.ContainsKey(key);
    }

    /// <summary>
    /// This method is not supported by the <see cref="T:ReadOnlyDictionary`2"/>.
    /// </summary>
    /// <param name="key">The key of the element to remove.</param>
    /// <returns>
    /// True if the element is successfully removed; otherwise, false.
    /// </returns>
    bool IDictionary<TKey, TValue>.Remove(TKey key)
    {
        ThrowNotSupportedException();
        return false;
    }

    /// <summary>
    /// Gets the value associated with the specified key.
    /// </summary>
    /// <param name="key">The key of the value to get.</param>
    /// <param name="value">When this method returns, contains the value
    /// associated with the specified key, if the key is found;
    /// otherwise, the default value for the type of the value parameter.
    /// This parameter is passed uninitialized.</param>
    /// <returns>
    /// <b>true</b> if the <see cref="T:ReadOnlyDictionary`2" /> contains
    /// an element with the specified key; otherwise, <b>false</b>.
    /// </returns>
    public bool TryGetValue(TKey key, out TValue value)
    {
        return this.source.TryGetValue(key, out value);
    }

    /// <summary>This method is not supported by the
    /// <see cref="T:ReadOnlyDictionary`2"/>.</summary>
    /// <param name="item">
    /// The object to add to the <see cref="T:ICollection`1"/>.
    /// </param>
    void ICollection<KeyValuePair<TKey, TValue>>.Add(
        KeyValuePair<TKey, TValue> item)
    {
        ThrowNotSupportedException();
    }

    /// <summary>This method is not supported by the 
    /// <see cref="T:ReadOnlyDictionary`2"/>.</summary>
    void ICollection<KeyValuePair<TKey, TValue>>.Clear()
    {
        ThrowNotSupportedException();
    }

    /// <summary>
    /// Determines whether the <see cref="T:ICollection`1"/> contains a
    /// specific value.
    /// </summary>
    /// <param name="item">
    /// The object to locate in the <see cref="T:ICollection`1"/>.
    /// </param>
    /// <returns>
    /// <b>true</b> if item is found in the <b>ICollection</b>; 
    /// otherwise, <b>false</b>.
    /// </returns>
    bool ICollection<KeyValuePair<TKey, TValue>>.Contains(
        KeyValuePair<TKey, TValue> item)
    {
        ICollection<KeyValuePair<TKey, TValue>> collection = this.source;

        return collection.Contains(item);
    }

    /// <summary>
    /// Copies the elements of the ICollection to an Array, starting at a
    /// particular Array index. 
    /// </summary>
    /// <param name="array">The one-dimensional Array that is the
    /// destination of the elements copied from ICollection.
    /// The Array must have zero-based indexing.
    /// </param>
    /// <param name="arrayIndex">
    /// The zero-based index in array at which copying begins.
    /// </param>
    void ICollection<KeyValuePair<TKey, TValue>>.CopyTo(
        KeyValuePair<TKey, TValue>[] array, int arrayIndex)
    {
        ICollection<KeyValuePair<TKey, TValue>> collection = this.source;
        collection.CopyTo(array, arrayIndex);
    }

    /// <summary>This method is not supported by the
    /// <see cref="T:ReadOnlyDictionary`2"/>.</summary>
    /// <param name="item">
    /// The object to remove from the ICollection.
    /// </param>
    /// <returns>Will never return a value.</returns>
    bool ICollection<KeyValuePair<TKey, TValue>>.Remove(KeyValuePair<TKey, TValue> item)
    {
        ThrowNotSupportedException();
        return false;
    }

    /// <summary>
    /// Returns an enumerator that iterates through the collection.
    /// </summary>
    /// <returns>
    /// A IEnumerator that can be used to iterate through the collection.
    /// </returns>
    IEnumerator<KeyValuePair<TKey, TValue>>
        IEnumerable<KeyValuePair<TKey, TValue>>.GetEnumerator()
    {
        IEnumerable<KeyValuePair<TKey, TValue>> enumerator = this.source;

        return enumerator.GetEnumerator();
    }

    /// <summary>
    /// Returns an enumerator that iterates through a collection.
    /// </summary>
    /// <returns>
    /// An IEnumerator that can be used to iterate through the collection.
    /// </returns>
    IEnumerator IEnumerable.GetEnumerator()
    {
        return this.source.GetEnumerator();
    }

    /// <summary>
    /// For a description of this member, see <see cref="ICollection.CopyTo"/>. 
    /// </summary>
    /// <param name="array">
    /// The one-dimensional Array that is the destination of the elements copied from 
    /// ICollection. The Array must have zero-based indexing.
    /// </param>
    /// <param name="index">
    /// The zero-based index in Array at which copying begins.
    /// </param>
    void ICollection.CopyTo(Array array, int index)
    {
        ICollection collection = 
            new List<KeyValuePair<TKey, TValue>>(this.source);

        collection.CopyTo(array, index);
    }

    private static void ThrowNotSupportedException()
    {
        throw new NotSupportedException("This Dictionary is read-only");
    }
}

internal sealed class ReadOnlyDictionaryDebugView<TKey, TValue>
{
    private IDictionary<TKey, TValue> dict;

    public ReadOnlyDictionaryDebugView(
        ReadOnlyDictionary<TKey, TValue> dictionary)
    {
        if (dictionary == null)
        {
            throw new ArgumentNullException("dictionary");
        }

        this.dict = dictionary;
    }

    [DebuggerBrowsable(DebuggerBrowsableState.RootHidden)]
    public KeyValuePair<TKey, TValue>[] Items
    {
        get
        {
            KeyValuePair<TKey, TValue>[] array =
                new KeyValuePair<TKey, TValue>[this.dict.Count];
            this.dict.CopyTo(array, 0);
            return array;
        }
    }
}
{{< / highlight >}}

## Comments

Comments haven't been migrated yet...